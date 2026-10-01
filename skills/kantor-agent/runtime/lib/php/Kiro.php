<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * Sesi Kiro CLI (~/.kiro/sessions/cli/*.json + *.jsonl) — read-only.
 * Isi Prompt user & ToolResults TIDAK PERNAH dibaca. Port Node: lib/node/kiro.mjs (dicek bin/parity.mjs).
 */
final class KKiro
{
    private const CACHE_V = 1;
    private const EVENTS_KEEP = 40;
    private const SEGS_KEEP = 20;
    private const FILES_KEEP = 12;
    private const TODOS_KEEP = 40;

    private ?string $cacheDir;
    private static ?array $lastGood = null;
    // ponytail: dir ditemukan sekali per proses; pindah KIRO_SESSIONS_DIR perlu restart server.
    private static ?string $dirMemo = null;
    // ponytail: cwd→toplevel/common-dir (null ikut dicache agar git gagal tidak diulang).
    /** @var array<string,?string> */
    private static array $topCache = [];
    /** @var array<string,?string> */
    private static array $commonCache = [];

    /** @param array<string,mixed> $cfg */
    public function __construct(private string $projectDir, ?string $storageDir, private array $cfg)
    {
        $this->cacheDir = $storageDir !== null ? $storageDir . '/cache' : null;
    }

    public static function dir(): string
    {
        if (self::$dirMemo !== null) {
            return self::$dirMemo;
        }
        $env = (string) getenv('KIRO_SESSIONS_DIR');
        if ($env !== '') {
            return self::$dirMemo = $env;
        }
        $home = (string) getenv('HOME');
        if ($home === '' && function_exists('posix_getpwuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        return self::$dirMemo = rtrim($home, '/') . '/.kiro/sessions/cli';
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    public function scan(int $nowSec): array
    {
        if (!is_dir(self::dir())) {
            return ['exists' => false, 'runs' => [], 'mains' => []];
        }
        try {
            return self::$lastGood = $this->scanDir($nowSec);
        } catch (Throwable) {
            // ponytail: file terkunci/rusak → hasil bagus terakhir, bukan kantor kosong.
            return self::$lastGood ?? ['exists' => true, 'runs' => [], 'mains' => []];
        }
    }

    /** @param list<string> $args */
    private static function gitOut(string $dir, array $args): ?string
    {
        $cmd = array_merge(['git', '-C', $dir], $args);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        foreach ([1, 2] as $i) {
            stream_set_blocking($pipes[$i], false);
        }
        $out = '';
        $deadline = microtime(true) + 5.0;
        $done = false;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $done = true;
                break;
            }
            $wait = $deadline - microtime(true);
            if ($wait <= 0) {
                break;
            }
            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            $sec = (int) $wait;
            $usec = (int) (($wait - $sec) * 1000000);
            @stream_select($r, $w, $e, $sec, $usec);
            foreach ($r as $s) {
                $chunk = @stream_get_contents($s);
                if ($s === $pipes[1] && is_string($chunk)) {
                    $out .= $chunk;
                }
            }
        }
        foreach ([1, 2] as $i) {
            $rest = @stream_get_contents($pipes[$i]);
            if ($i === 1 && is_string($rest)) {
                $out .= $rest;
            }
            fclose($pipes[$i]);
        }
        if (!$done) {
            @proc_terminate($proc);
        }
        @proc_close($proc);
        if (!$done) {
            return null;
        }
        $t = trim($out);
        return $t !== '' ? $t : null;
    }

    private static function topLevel(mixed $dir): ?string
    {
        if (!is_string($dir) || $dir === '') {
            return null;
        }
        if (array_key_exists($dir, self::$topCache)) {
            return self::$topCache[$dir];
        }
        $top = self::gitOut($dir, ['rev-parse', '--show-toplevel']);
        return self::$topCache[$dir] = $top;
    }

    private static function commonDir(mixed $dir): ?string
    {
        if (!is_string($dir) || $dir === '') {
            return null;
        }
        if (array_key_exists($dir, self::$commonCache)) {
            return self::$commonCache[$dir];
        }
        $c = self::gitOut($dir, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        return self::$commonCache[$dir] = $c;
    }

    private static function sameRepo(mixed $a, string $b, ?string $projTop, ?string $projCommon): bool
    {
        $t = self::topLevel($a);
        if ($t !== null && $projTop !== null && $t === $projTop) {
            return true;
        }
        $c = self::commonDir($a);
        return $c !== null && $projCommon !== null && $c === $projCommon;
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    private function scanDir(int $nowSec): array
    {
        $cutoff = ($nowSec - (int) $this->cfg['window_days'] * 86400) * 1000;
        $names = [];
        foreach (scandir(self::dir()) ?: [] as $n) {
            if (str_ends_with($n, '.json') && !str_starts_with($n, '.')) {
                $names[] = $n;
            }
        }
        sort($names, SORT_STRING);
        $mains = [];
        $runs = [];
        $projTop = self::topLevel($this->projectDir);
        $projCommon = self::commonDir($this->projectDir);
        foreach ($names as $n) {
            $h = json_decode((string) @file_get_contents(self::dir() . '/' . $n), true);
            if (!is_array($h) || ($h === [] || !array_is_list($h)) === false) {
                continue;
            }
            if (!is_string($h['session_id'] ?? null)) {
                continue;
            }
            // ponytail: worktree = cwd beda tapi satu repo (toplevel untuk subdir, common-dir untuk worktree).
            if (($h['cwd'] ?? null) !== $this->projectDir && !self::sameRepo($h['cwd'] ?? null, $this->projectDir, $projTop, $projCommon)) {
                continue;
            }
            $upd = KUtil::tsMs($h['updated_at'] ?? null);
            if ($upd === null || $upd < $cutoff) {
                continue;
            }
            if (count($mains) + count($runs) >= (int) $this->cfg['mains_max'] * 2) {
                break;
            }
            $sum = $this->summarize($h);
            $agent = self::agentName($h) ?? 'general-purpose';
            $item = $sum + [
                'session' => (string) $h['session_id'],
                'provider' => 'kiro',
                'agentType' => KUtil::clip($agent, 40),
                'description' => is_string($h['title'] ?? null) ? KUtil::safeLine((string) $h['title'], 140) : '',
            ];
            if (self::isSub($h)) {
                // ponytail: kiro tidak mencatat sesi induk → peminta selalu Ketua (parentAgent null).
                $item['id'] = (string) $h['session_id'];
                $item['parentAgent'] = null;
                if ($item['started'] !== null) {
                    $runs[] = $item;
                }
            } elseif (count($mains) < (int) $this->cfg['mains_max']) {
                $mains[] = $item;
            }
        }
        usort($runs, static fn($a, $b) => ($a['started'] <=> $b['started']) ?: strcmp((string) $a['id'], (string) $b['id']));
        return ['exists' => true, 'runs' => $runs, 'mains' => $mains];
    }

    /** @param array<string,mixed> $h @return array<string,mixed> */
    public function summarize(array $h): array
    {
        $sid = (string) $h['session_id'];
        $cacheFile = $this->cacheDir !== null ? $this->cacheDir . '/k-' . md5($sid) . '.json' : null;
        $s = null;
        if ($cacheFile !== null && is_file($cacheFile)) {
            $c = json_decode((string) @file_get_contents($cacheFile), true);
            // ponytail: sesi tidak berubah (updated_at sama) → pakai cache.
            if (is_array($c) && ($c['v'] ?? 0) === self::CACHE_V && ($c['updated'] ?? null) === ($h['updated_at'] ?? null)) {
                $s = $c['s'];
            }
        }
        if (!is_array($s)) {
            $s = self::fresh();
            $raw = @file_get_contents(self::dir() . '/' . $sid . '.jsonl');
            if (is_string($raw)) {
                foreach (explode("\n", $raw) as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $row = json_decode($line, true);
                    if (is_array($row) && ($row === [] || !array_is_list($row))) {
                        $this->consume($s, $row);
                    }
                }
            }
            unset($s['cur']);
            $n = count($s['segs']);
            if ($s['started'] !== null && $n > 0 && $s['segs'][$n - 1][1] === null && $s['updated'] !== null) {
                $s['segs'][$n - 1][1] = $s['updated'];
            }
            if ($cacheFile !== null && (is_dir(dirname($cacheFile)) || @mkdir(dirname($cacheFile), 0775, true))) {
                @file_put_contents($cacheFile, json_encode(['v' => self::CACHE_V, 'updated' => $h['updated_at'] ?? null, 's' => $s], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
            }
        }
        return [
            'started' => $s['started'], 'updated' => $s['updated'], 'tools' => $s['tools'], 'tokens' => 0,
            'events' => $s['events'], 'lastKind' => $s['lastKind'], 'limit' => $s['limit'], 'files' => $s['files'],
            'todos' => $s['todos'], 'todosAt' => $s['todosAt'], 'todoSource' => $s['todoSource'], 'segs' => $s['segs'],
            'stops' => [],
        ];
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $row */
    private function consume(array &$s, array $row): void
    {
        $kind = $row['kind'] ?? '';
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        if ($kind === 'Prompt') {
            // ponytail: satu-satunya baris bertimestamp; isi prompt tidak pernah ditampilkan.
            $ts = isset($data['meta']['timestamp']) && is_numeric($data['meta']['timestamp']) ? ((int) $data['meta']['timestamp']) * 1000 : null;
            if ($ts === null) {
                return;
            }
            $s['cur'] = $ts;
            $t = KUtil::isoMs($ts);
            if ($s['started'] === null) {
                $s['started'] = $t;
                $s['segs'][] = [$t, null];
            }
            $s['updated'] = $t;
            if (!count($s['segs'])) {
                $s['segs'][] = [$t, null];
            }
            $this->push($s, $t, 'user', 'Instruksi dari user', null);
            $s['lastKind'] = 'user';
            return;
        }
        if ($kind !== 'AssistantMessage') {
            return; // ToolResults/Compaction: privasi — dilewati total.
        }
        if (!isset($s['cur']) || $s['started'] === null) {
            return;
        }
        $t = KUtil::isoMs($s['cur']);
        $s['updated'] = $t;
        if (!count($s['segs'])) {
            $s['segs'][] = [$t, null];
        }
        // ponytail: tiap pesan = satu seg; digabung bila jeda ≤ cooldown supaya satu sesi = satu job.
        $n = count($s['segs']);
        if ($s['segs'][$n - 1][1] !== null) {
            if ($s['cur'] - KUtil::tsMs($s['segs'][$n - 1][1]) <= (int) $this->cfg['cooldown'] * 1000) {
                $s['segs'][$n - 1][1] = null;
            } else {
                $s['segs'][] = [$t, null];
                if (count($s['segs']) > self::SEGS_KEEP) {
                    array_shift($s['segs']);
                }
            }
        }
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        foreach ($content as $b) {
            if (!is_array($b)) {
                continue;
            }
            $bt = $b['kind'] ?? '';
            $bd = is_array($b['data'] ?? null) ? $b['data'] : [];
            if ($bt === 'text') {
                $txt = is_string($bd) ? trim($bd) : trim((string) ($bd['text'] ?? ''));
                if ($txt === '') {
                    continue;
                }
                if (preg_match('/(usage limit|rate limit|limit reached|resets? (at|in))/i', $txt) && mb_strlen($txt) < 400) {
                    $s['limit'] = KUtil::clip(KUtil::redact($txt), 200);
                }
                $this->push($s, $t, 'text', KUtil::safeLine($txt, 180), null);
                $s['lastKind'] = 'text';
            } elseif ($bt === 'toolUse') {
                $name = is_string($bd['name'] ?? null) ? $bd['name'] : '?';
                $in = is_array($bd['input'] ?? null) ? $bd['input'] : [];
                $s['tools']++;
                [$text, $files] = $this->describeTool($name, $in);
                foreach (array_slice($files, 0, self::FILES_KEEP) as $f) {
                    $s['files'] = array_values(array_filter($s['files'], static fn($x) => $x !== $f));
                    $s['files'][] = $f;
                    if (count($s['files']) > self::FILES_KEEP) {
                        array_shift($s['files']);
                    }
                }
                if ($name === 'todo_list' && is_array($in['tasks'] ?? null)) {
                    $items = [];
                    foreach ($in['tasks'] as $td) {
                        if (!is_array($td) || trim((string) ($td['task_description'] ?? '')) === '') {
                            continue;
                        }
                        $items[] = ['text' => KUtil::safeLine((string) $td['task_description'], 120), 'status' => self::todoStatus($td['status'] ?? $td['task_status'] ?? null)];
                        if (count($items) >= self::TODOS_KEEP) {
                            break;
                        }
                    }
                    $s['todos'] = $items;
                    $s['todosAt'] = $t;
                    $s['todoSource'] = 'TodoWrite';
                }
                $this->push($s, $t, 'tool', $text, $name);
                $s['lastKind'] = 'tool';
                $s['limit'] = null;
            }
            // thinking: tanpa event.
        }
    }

    /** @param array<string,mixed> $s */
    private function push(array &$s, string $t, string $kind, string $text, ?string $tool): void
    {
        $s['events'][] = ['t' => $t, 'kind' => $kind, 'text' => $text, 'tool' => $tool];
        if (count($s['events']) > self::EVENTS_KEEP) {
            array_splice($s['events'], 0, count($s['events']) - self::EVENTS_KEEP);
        }
    }

    /** @param array<string,mixed> $in @return array{0:string,1:list<string>} */
    private function describeTool(string $name, array $in): array
    {
        $str = static fn($v): string => is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        $rel = fn($p): string => str_starts_with($p, $this->projectDir . '/') ? substr($p, strlen($this->projectDir) + 1) : basename($p);
        $files = [];
        $text = match ($name) {
            'read' => (function () use ($in, $rel, &$files) {
                $ops = is_array($in['operations'] ?? null) ? array_filter($in['operations'], 'is_array') : [];
                $first = null;
                foreach ($ops as $o) {
                    if (is_string($o['path'] ?? null)) {
                        $first = $o;
                        break;
                    }
                }
                foreach ($ops as $o) {
                    if (($o['mode'] ?? null) === 'File' && is_string($o['path'] ?? null)) {
                        $files[] = $rel($o['path']);
                    }
                }
                return 'Membaca ' . ($first !== null ? $rel($first['path']) : '');
            })(),
            'write' => (function () use ($in, $rel, &$files) {
                if (is_string($in['path'] ?? null) && $in['path'] !== '') {
                    $files = [$rel($in['path'])];
                    return 'Menulis ' . $rel($in['path']);
                }
                return 'Menulis ';
            })(),
            // ponytail: argumen shell tidak pernah ditampilkan — purpose saja.
            'shell' => 'Menjalankan: ' . ($str($in['__tool_use_purpose'] ?? null) !== '' ? $str($in['__tool_use_purpose']) : self::firstToken($str($in['command'] ?? null)) . ' …'),
            'grep' => "Mencari '" . KUtil::clip($str($in['pattern'] ?? null), 50) . "'",
            'glob' => 'Mencari file ' . KUtil::clip($str($in['pattern'] ?? null), 60),
            'todo_list' => 'Memperbarui daftar tugas',
            'switch_to_execution' => 'Melanjutkan ke eksekusi',
            'introspect' => "Mencari '" . KUtil::clip($str($in['query'] ?? null), 50) . "'",
            default => KUtil::clip($name, 60),
        };
        return [KUtil::oneLine($text, 160), array_slice($files, 0, self::FILES_KEEP)];
    }

    /** @param array<string,mixed> $h */
    private static function isSub(array $h): bool
    {
        if (($h['session_created_reason'] ?? null) === 'subagent') {
            return true;
        }
        foreach ((array) ($h['session_state']['conversation_metadata']['user_turn_metadatas'] ?? []) as $m) {
            $a = is_array($m) ? ($m['loop_id']['agent_id'] ?? null) : null;
            if (is_array($a) && ($a['parent_id'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $h */
    private static function agentName(array $h): ?string
    {
        foreach ((array) ($h['session_state']['conversation_metadata']['user_turn_metadatas'] ?? []) as $m) {
            $a = is_array($m) ? ($m['loop_id']['agent_id'] ?? null) : null;
            if (is_array($a) && is_string($a['name'] ?? null) && trim($a['name']) !== '') {
                return trim($a['name']);
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function fresh(): array
    {
        return ['started' => null, 'updated' => null, 'tools' => 0, 'events' => [], 'lastKind' => null,
            'limit' => null, 'files' => [], 'todos' => null, 'todosAt' => null, 'todoSource' => null,
            'segs' => [], 'cur' => null];
    }

    private static function todoStatus(mixed $v): string
    {
        return in_array($v, ['pending', 'in_progress', 'completed'], true) ? $v : 'pending';
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
