<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Kiro.php';
require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/Omp.php';
require_once __DIR__ . '/Gemini.php';

/**
 * Ringkasan sesi OpenCode dari SQLite (~/.local/share/opencode/opencode.db) — read-only.
 * Hanya metadata tool & potongan teks assistant. Output tool (tool_result) TIDAK PERNAH dibaca.
 * Port Node: lib/node/transcripts.mjs (harus identik — dicek bin/parity.mjs).
 */
final class KTranscripts
{
    private const CACHE_V = 3;
    private const EVENTS_KEEP = 40;
    private const SEGS_KEEP = 20;
    private const FILES_KEEP = 12;
    private const TODOS_KEEP = 40;

    private ?string $cacheDir;
    private static ?array $lastGood = null;
    private ?string $storageDir;
    // ponytail: DB ditemukan sekali per proses; bila user memindah OPENCODE_DB perlu restart server.
    private static ?string $dbMemo = null;

    /** @param array<string,mixed> $cfg */
    public function __construct(private string $projectDir, ?string $storageDir, private array $cfg)
    {
        $this->storageDir = $storageDir;
        $this->cacheDir = $storageDir !== null ? $storageDir . '/cache' : null;
    }

    public static function dbPath(): string
    {
        if (self::$dbMemo !== null) {
            return self::$dbMemo;
        }
        $env = (string) getenv('OPENCODE_DB');
        if ($env !== '') {
            return self::$dbMemo = $env;
        }
        $data = (string) getenv('XDG_DATA_HOME');
        if ($data === '') {
            $home = (string) getenv('HOME');
            if ($home === '' && function_exists('posix_getpwuid')) {
                $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
            }
            $data = rtrim($home, '/') . '/.local/share';
        }
        return self::$dbMemo = rtrim($data, '/') . '/opencode/opencode.db';
    }

    /** @return list<array<string,mixed>> */
    private static function query(PDO $db, string $sql, array $params): array
    {
        $st = $db->prepare($sql);
        $st->execute($params);
        /** @var list<array<string,mixed>> */
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    public function scan(int $nowSec): array
    {
        // ponytail: lima sumber (OpenCode DB + Kiro CLI + Claude Code + OMP + Gemini) digabung; office yang memilih Ketua & membagi tim.
        $oc = ['exists' => false, 'runs' => [], 'mains' => []];
        if (is_file(self::dbPath())) {
            try {
                $oc = self::$lastGood = $this->scanDb($nowSec);
            } catch (Throwable) {
                // ponytail: DB terkunci/sibuk → sajikan hasil bagus terakhir, bukan kantor kosong.
                $oc = self::$lastGood ?? ['exists' => true, 'runs' => [], 'mains' => []];
            }
        }
        $k = (new KKiro($this->projectDir, $this->storageDir, $this->cfg))->scan($nowSec);
        $c = (new KClaude($this->projectDir, $this->storageDir, $this->cfg))->scan($nowSec);
        $o = (new KOmp($this->projectDir, $this->storageDir, $this->cfg))->scan($nowSec);
        $g = (new KGemini($this->projectDir, $this->storageDir, $this->cfg))->scan($nowSec);
        return ['exists' => $oc['exists'] || $k['exists'] || $c['exists'] || $o['exists'] || $g['exists'],
            'runs' => [...$oc['runs'], ...$k['runs'], ...$c['runs'], ...$o['runs'], ...$g['runs']], 'mains' => [...$oc['mains'], ...$k['mains'], ...$c['mains'], ...$o['mains'], ...$g['mains']]];
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    private function scanDb(int $nowSec): array
    {
        $db = new PDO('sqlite:' . self::dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=5000; PRAGMA query_only=ON');
        try {
            $cutoff = ($nowSec - (int) $this->cfg['window_days'] * 86400) * 1000;
            $sel = 'SELECT id,parent_id,directory,title,agent,time_created,time_updated FROM session';
            $rows = null;
            try {
                $rows = self::query($db,
                    $sel . '
                     WHERE project_id IN (SELECT project_id FROM project_directory WHERE directory=?) AND time_updated>=? AND time_archived IS NULL ORDER BY time_updated DESC',
                    [$this->projectDir, $cutoff]);
            } catch (Throwable) { $rows = null; } // ponytail: DB lama tanpa tabel project_directory → fallback exact-match.
            if ($rows === null) {
                $rows = self::query($db,
                    $sel . '
                     WHERE directory=? AND time_updated>=? AND time_archived IS NULL ORDER BY time_updated DESC',
                    [$this->projectDir, $cutoff]);
            } elseif (count($rows) === 0) {
                // ponytail: COUNT ringan saja bila kosong; project lama tanpa baris project_directory → fallback.
                $n = 0;
                try {
                    $c = self::query($db, 'SELECT COUNT(*) AS n FROM session WHERE directory=? AND time_updated>=? AND time_archived IS NULL',
                        [$this->projectDir, $cutoff]);
                    $n = (int) ($c[0]['n'] ?? 0);
                } catch (Throwable) { $n = 0; }
                if ($n > 0) {
                    $rows = self::query($db,
                        $sel . '
                         WHERE directory=? AND time_updated>=? AND time_archived IS NULL ORDER BY time_updated DESC',
                        [$this->projectDir, $cutoff]);
                }
            }
            $mains = [];
            $kids = [];
            foreach ($rows as $r) {
                if (count($mains) + count($kids) >= (int) $this->cfg['mains_max'] * 2) {
                    break;
                }
                $sum = $this->summarize($db, $r);
                $item = $sum + [
                    'session' => $r['parent_id'] ?? $r['id'],
                    'provider' => 'opencode',
                    'agentType' => is_string($r['agent'] ?? null) && trim((string) $r['agent']) !== '' ? KUtil::clip(trim((string) $r['agent']), 40) : 'general-purpose',
                    'description' => is_string($r['title'] ?? null) ? KUtil::safeLine((string) $r['title'], 140) : '',
                ];
                if ($r['parent_id'] === null) {
                    if (count($mains) < (int) $this->cfg['mains_max']) {
                        $mains[] = $item;
                    }
                } else {
                    // ponytail: parent juga run (subagent bersarang) → office tahu pemintanya; kalau sesi utama → Ketua.
                    $item['id'] = (string) $r['id'];
                    $item['parentAgent'] = $r['parent_id'];
                    $kids[] = $item;
                }
            }
            usort($kids, static fn($a, $b) => ($a['started'] <=> $b['started']) ?: strcmp((string) $a['id'], (string) $b['id']));
            $kids = array_values(array_filter($kids, static fn($r) => $r['started'] !== null));
            return ['exists' => true, 'runs' => $kids, 'mains' => $mains];
        } finally {
            $db = null;
        }
    }

    /** @param array<string,mixed> $sess @return array<string,mixed> */
    public function summarize(PDO $db, array $sess): array
    {
        $cacheFile = $this->cacheDir !== null ? $this->cacheDir . '/o-' . md5((string) $sess['id']) . '.json' : null;
        $s = null;
        if ($cacheFile !== null && is_file($cacheFile)) {
            $c = json_decode((string) @file_get_contents($cacheFile), true);
            // ponytail: sesi tidak berubah (time_updated sama) → pakai cache, tanpa baca DB lagi.
            if (is_array($c) && ($c['v'] ?? 0) === self::CACHE_V && ($c['updated'] ?? null) == $sess['time_updated']) {
                $s = $c['s'];
            }
        }
        if (!is_array($s)) {
            $s = self::fresh();
            $rows = self::query($db,
                'SELECT p.data AS pdata,p.time_created AS pt,m.id AS mid,m.data AS mdata FROM part p
                 JOIN message m ON m.id=p.message_id WHERE p.session_id=? ORDER BY p.time_created,p.id',
                [(string) $sess['id']]);
            foreach ($rows as $row) {
                $p = json_decode((string) $row['pdata'], true);
                $m = json_decode((string) $row['mdata'], true);
                if (is_array($p) && ($p === [] || !array_is_list($p))) {
                    $this->consume($s, $p, is_array($m) && ($m === [] || !array_is_list($m)) ? $m : [], (string) ($row['mid'] ?? ''), (int) $row['pt']);
                }
            }
            // ponytail: tabel todo sebagai fallback bila tidak ada part todowrite yang lebih baru.
            try {
                $todos = self::query($db, 'SELECT content,status,time_updated FROM todo WHERE session_id=? ORDER BY position', [(string) $sess['id']]);
                $tMax = 0;
                foreach ($todos as $td) {
                    $tMax = max($tMax, (int) ($td['time_updated'] ?? 0));
                }
                if (count($todos) && ($s['todosAtMs'] === null || $tMax >= $s['todosAtMs'])) {
                    $items = [];
                    foreach (array_slice($todos, 0, self::TODOS_KEEP) as $td) {
                        if (trim((string) ($td['content'] ?? '')) === '') {
                            continue;
                        }
                        $items[] = ['text' => KUtil::safeLine((string) $td['content'], 120), 'status' => self::todoStatus($td['status'] ?? null)];
                    }
                    $s['todos'] = $items;
                    $s['todosAt'] = KUtil::isoMs($tMax);
                    $s['todoSource'] = 'Task';
                }
            } catch (Throwable) { /* tabel todo opsional */ }
            if ($cacheFile !== null && (is_dir(dirname($cacheFile)) || @mkdir(dirname($cacheFile), 0775, true))) {
                @file_put_contents($cacheFile, json_encode(['v' => self::CACHE_V, 'updated' => $sess['time_updated'], 's' => $s], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
            }
        }
        return [
            'started' => $s['started'], 'updated' => $s['updated'], 'tools' => $s['tools'],
            'tokens' => $s['tokens']['in'] + $s['tokens']['out'] + $s['tokens']['cache'],
            'events' => $s['events'], 'lastKind' => $s['lastKind'], 'limit' => $s['limit'], 'files' => $s['files'],
            'todos' => $s['todos'], 'todosAt' => $s['todosAt'], 'todoSource' => $s['todoSource'], 'segs' => $s['segs'],
            'stops' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function fresh(): array
    {
        return ['started' => null, 'updated' => null, 'tools' => 0, 'tokens' => ['in' => 0, 'out' => 0, 'cache' => 0],
            'lastMsgId' => null, 'events' => [], 'lastKind' => null, 'limit' => null, 'files' => [],
            'todos' => null, 'todosAt' => null, 'todosAtMs' => null, 'todoSource' => null, 'segs' => []];
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $p @param array<string,mixed> $m */
    private function consume(array &$s, array $p, array $m, string $mid, int $ms): void
    {
        $t = KUtil::isoMs($ms);
        $s['started'] ??= $t;
        $s['updated'] = $t;
        if (!count($s['segs'])) {
            $s['segs'][] = [$t, null];
        }
        // token dihitung sekali per pesan (step-finish menduplikasi angka yang sama).
        if ($mid !== '' && $mid !== $s['lastMsgId'] && is_array($m['tokens'] ?? null)) {
            $u = $m['tokens'];
            $s['tokens']['in'] += self::int($u['input'] ?? 0);
            $s['tokens']['out'] += self::int($u['output'] ?? 0) + self::int($u['reasoning'] ?? 0);
            $s['tokens']['cache'] += self::int($u['cache']['read'] ?? 0) + self::int($u['cache']['write'] ?? 0);
            $s['lastMsgId'] = $mid;
        }
        $role = $m['role'] ?? '';
        $type = $p['type'] ?? '';
        if ($type === 'text') {
            $txt = is_string($p['text'] ?? null) ? trim((string) $p['text']) : '';
            if ($txt === '') {
                return;
            }
            if ($role === 'user') {
                // ponytail: prompt user tidak pernah ditampilkan — hanya penanda (warisan perilaku Claude).
                $this->push($s, $t, 'user', 'Instruksi dari user', null);
                $s['lastKind'] = 'user';
            } else {
                if (preg_match('/(usage limit|rate limit|limit reached|resets? (at|in))/i', $txt) && mb_strlen($txt) < 400) {
                    $s['limit'] = KUtil::clip(KUtil::redact($txt), 200);
                }
                $this->push($s, $t, 'text', KUtil::safeLine($txt, 180), null);
                $s['lastKind'] = 'text';
            }
            return;
        }
        if ($type === 'tool') {
            $name = is_string($p['tool'] ?? null) ? $p['tool'] : '?';
            $in = is_array($p['state']['input'] ?? null) ? $p['state']['input'] : [];
            $s['tools']++;
            [$text, $f] = $this->describeTool($name, $in);
            if ($f !== null && in_array($name, ['write', 'edit'], true)) {
                $s['files'] = array_values(array_filter($s['files'], static fn($x) => $x !== $f));
                $s['files'][] = $f;
                if (count($s['files']) > self::FILES_KEEP) {
                    array_shift($s['files']);
                }
            }
            if ($name === 'todowrite' && is_array($in['todos'] ?? null)) {
                $items = [];
                foreach ($in['todos'] as $td) {
                    if (!is_array($td) || trim((string) ($td['content'] ?? '')) === '') {
                        continue;
                    }
                    $items[] = ['text' => KUtil::safeLine((string) $td['content'], 120), 'status' => self::todoStatus($td['status'] ?? null)];
                    if (count($items) >= self::TODOS_KEEP) {
                        break;
                    }
                }
                $s['todos'] = $items;
                $s['todosAt'] = $t;
                $s['todosAtMs'] = $ms;
                $s['todoSource'] = 'TodoWrite';
            }
            $this->push($s, $t, 'tool', $text, $name);
            $s['lastKind'] = 'tool';
            $s['limit'] = null;
            return;
        }
        if ($type === 'step-start') {
            // ponytail: tiap tool = satu step; seg digabung bila jeda ≤ cooldown supaya satu sesi = satu job.
            $n = count($s['segs']);
            $last = $s['segs'][$n - 1];
            if ($last[1] !== null) {
                if ($ms - KUtil::tsMs($last[1]) <= (int) $this->cfg['cooldown'] * 1000) {
                    $s['segs'][$n - 1][1] = null;
                } else {
                    $s['segs'][] = [$t, null];
                    if (count($s['segs']) > self::SEGS_KEEP) {
                        array_shift($s['segs']);
                    }
                }
            }
            return;
        }
        if ($type === 'step-finish') {
            $n = count($s['segs']);
            if ($s['segs'][$n - 1][1] === null) {
                $s['segs'][$n - 1][1] = $t;
            }
            if (($p['reason'] ?? '') === 'stop' && $s['lastKind'] !== 'user') {
                $s['lastKind'] = 'final';
            }
            return;
        }
        if ($type === 'patch' && is_array($p['files'] ?? null)) {
            // ponytail: patch hanya menyumbang daftar file, tanpa event.
            foreach ($p['files'] as $f) {
                $rel = is_string($f) && str_starts_with($f, $this->projectDir . '/') ? substr($f, strlen($this->projectDir) + 1) : basename((string) ($f ?? ''));
                if ($rel === '') {
                    continue;
                }
                $s['files'] = array_values(array_filter($s['files'], static fn($x) => $x !== $rel));
                $s['files'][] = $rel;
                if (count($s['files']) > self::FILES_KEEP) {
                    array_shift($s['files']);
                }
            }
        }
        // reasoning/compaction: aktivitas berpikir — seg sudah dibuka di atas, tanpa event.
    }

    /** @param array<string,mixed> $s */
    private function push(array &$s, string $t, string $kind, string $text, ?string $tool): void
    {
        $s['events'][] = ['t' => $t, 'kind' => $kind, 'text' => $text, 'tool' => $tool];
        if (count($s['events']) > self::EVENTS_KEEP) {
            array_splice($s['events'], 0, count($s['events']) - self::EVENTS_KEEP);
        }
    }

    /** @param array<string,mixed> $in @return array{0:string,1:?string} */
    private function describeTool(string $name, array $in): array
    {
        $str = static fn($v): string => is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        $f = null;
        if (is_string($in['filePath'] ?? null) && $in['filePath'] !== '') {
            $p = $in['filePath'];
            $f = str_starts_with($p, $this->projectDir . '/') ? substr($p, strlen($this->projectDir) + 1) : basename($p);
        }
        $text = match ($name) {
            'read' => 'Membaca ' . $f,
            'write' => 'Menulis ' . $f,
            'edit' => 'Mengubah ' . $f,
            // ponytail: argumen Bash tidak pernah ditampilkan — deskripsi saja (warisan perilaku Claude).
            'bash' => 'Menjalankan: ' . ($str($in['description'] ?? null) !== '' ? $str($in['description']) : self::firstToken($str($in['command'] ?? null)) . ' …'),
            'grep' => "Mencari '" . KUtil::clip($str($in['pattern'] ?? null), 50) . "'",
            'glob' => 'Mencari file ' . KUtil::clip($str($in['pattern'] ?? null), 60),
            'task' => 'Mendelegasikan: ' . ($str($in['description'] ?? null) !== '' ? $str($in['description']) : 'subagent'),
            'question' => 'Bertanya ke user',
            'webfetch', 'websearch' => 'Riset web',
            'todowrite' => 'Memperbarui daftar tugas',
            'skill' => 'Memuat skill ' . $str($in['skill'] ?? null),
            default => KUtil::clip($name, 60),
        };
        return [KUtil::oneLine($text, 160), $f];
    }

    private static function todoStatus(mixed $v): string
    {
        return in_array($v, ['pending', 'in_progress', 'completed'], true) ? $v : 'pending';
    }

    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : (is_float($v) && is_finite($v) ? (int) $v : 0);
    }

    private static function firstToken(string $cmd): string
    {
        foreach (preg_split('/[ \n]+/', $cmd) ?: [] as $tok) {
            if ($tok !== '') {
                return $tok;
            }
        }
        return '';
    }
}
