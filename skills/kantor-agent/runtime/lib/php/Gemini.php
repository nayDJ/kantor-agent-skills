<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * Sesi Gemini CLI (~/.gemini/tmp/<slug|hash>/chats/) — read-only.
 * Hasil tool & prompt user TIDAK PERNAH dibaca. Port Node: lib/node/gemini.mjs (dicek bin/parity.mjs).
 */
final class KGemini
{
    private const CACHE_V = 1;
    private const HEAD_MAX = 1024;
    private const EVENTS_KEEP = 40;
    private const SEGS_KEEP = 20;
    private const FILES_KEEP = 12;
    private const TODOS_KEEP = 40; // ponytail: format tak punya todo → konstanta dokumentasi saja

    private ?string $cacheDir;
    private static ?array $lastGood = null;
    // ponytail: dir ditemukan sekali per proses; pindah GEMINI_SESSIONS_DIR perlu restart server.
    private static ?string $dirMemo = null;
    private static ?string $regMemo = null;
    /** @var array<string,array{size:int,mtimeMs:int,s:array<string,mixed>,head:string}> */
    private static array $memo = [];

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
        $env = (string) getenv('GEMINI_SESSIONS_DIR');
        if ($env !== '') {
            return self::$dirMemo = rtrim($env, '/');
        }
        return self::$dirMemo = rtrim(self::home(), '/') . '/.gemini/tmp';
    }

    private static function home(): string
    {
        $h = (string) getenv('GEMINI_CLI_HOME');
        if ($h === '') {
            $h = (string) getenv('HOME');
        }
        if ($h === '' && function_exists('posix_getpwuid')) {
            $h = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        return $h !== '' ? $h : '/root';
    }

    private static function registryFile(): string
    {
        if (self::$regMemo !== null) {
            return self::$regMemo;
        }
        if ((string) getenv('GEMINI_SESSIONS_DIR') !== '') {
            return self::$regMemo = dirname(self::dir()) . '/projects.json';
        }
        return self::$regMemo = rtrim(self::home(), '/') . '/.gemini/projects.json';
    }

    // ponytail: cermin ProjectRegistry upstream (slugify + normalizePath minimal).
    private static function slugify(string $text): string
    {
        $s = (string) preg_replace('/[^a-z0-9]/', '-', strtolower($text));
        $s = (string) preg_replace('/-+/', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? $s : 'project';
    }

    private static function norm(string $p): string
    {
        $r = @realpath($p);
        if (is_string($r) && $r !== '') {
            return rtrim($r, '/') !== '' ? rtrim($r, '/') : $r;
        }
        $abs = str_starts_with($p, '/') ? $p : (string) getcwd() . '/' . $p;
        $parts = [];
        foreach (explode('/', $abs) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $r = '/' . implode('/', $parts);
        return rtrim($r, '/') !== '' ? rtrim($r, '/') : $r;
    }

    private static function markerOf(string $slugDir): ?string
    {
        $t = @file_get_contents($slugDir . '/.project_root');
        if (!is_string($t)) {
            return null;
        }
        return self::norm(trim($t));
    }

    /** @return array<string,bool> */
    private static function candidateHashes(string $projectDir): array
    {
        $out = [];
        $raws = [$projectDir];
        $rp = @realpath($projectDir);
        if (is_string($rp) && $rp !== '') {
            $raws[] = $rp;
        }
        $raws[] = self::norm($projectDir);
        // ponytail: path.resolve di Node tak menyentuh symlink; norm di sini = resolve manual yang sama.
        foreach ($raws as $r) {
            $out[hash('sha256', $r)] = true;
            $out[hash('sha256', rtrim($r, '/'))] = true;
        }
        return $out;
    }

    // metadata baris pertama .jsonl (tanpa parse penuh) → projectHash sesi
    private static function peekHash(string $file): ?string
    {
        $fd = @fopen($file, 'rb');
        if (!is_resource($fd)) {
            return null;
        }
        try {
            $b = fread($fd, 4096);
            if (!is_string($b)) {
                return null;
            }
            $line = explode("\n", $b)[0] ?? '';
            $row = json_decode($line, true);
            if (self::isPlainObj($row) && isset($row['projectHash']) && is_string($row['projectHash'])) {
                return $row['projectHash'];
            }
            if (self::isPlainObj($row) && isset($row['sessionId']) && is_string($row['sessionId']) && isset($row['messages']) && is_array($row['messages'])) {
                return isset($row['projectHash']) && is_string($row['projectHash']) ? $row['projectHash'] : null;
            }
        } catch (Throwable) {
            /* abaikan */
        } finally {
            fclose($fd);
        }
        return null;
    }

    /** @return list<string> */
    private static function projectRoots(string $projectDir): array
    {
        $base = self::dir();
        if (!is_dir($base)) {
            return [];
        }
        $want = self::norm($projectDir);
        $found = [];
        $add = function (string $chats) use (&$found): void {
            if (is_dir($chats) && !in_array($chats, $found, true)) {
                $found[] = $chats;
            }
        };
        // 1. registry modern projects.json
        try {
            $reg = json_decode((string) @file_get_contents(self::registryFile()), true);
            $slug = self::isPlainObj($reg) && self::isPlainObj($reg['projects'] ?? null) ? ($reg['projects'][$want] ?? null) : null;
            if (is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug)) {
                $add($base . '/' . $slug . '/chats');
            }
        } catch (Throwable) {
            /* registry opsional */
        }
        $names = @scandir($base);
        if ($names === false) {
            return $found;
        }
        $names = array_values(array_filter($names, static fn($x) => $x !== '' && $x[0] !== '.'));
        sort($names, SORT_STRING);
        // 2. marker .project_root yang cocok
        foreach ($names as $n) {
            $d = $base . '/' . $n;
            if (!is_dir($d)) {
                continue;
            }
            if (self::markerOf($d) === $want) {
                $add($d . '/chats');
            }
        }
        if (count($found)) {
            return $found;
        }
        // 3. kandidat sha256 lawas
        $hashes = self::candidateHashes($projectDir);
        foreach ($names as $n) {
            if (!preg_match('/^[a-f0-9]{64}$/', $n) || !isset($hashes[$n])) {
                continue;
            }
            $d = $base . '/' . $n;
            $m = self::markerOf($d);
            if ($m === null || $m === $want) {
                $add($d . '/chats');
            }
        }
        if (count($found)) {
            return $found;
        }
        // 4. FALLBACK: pindai semua slug, cocokkan metadata projectHash
        $slug = self::slugify(self::basename($want));
        foreach ($names as $n) {
            if (preg_match('/^[a-f0-9]{64}$/', $n)) {
                continue;
            }
            if ($n !== $slug && !str_starts_with($n, $slug . '-')) {
                continue;
            }
            $chats = $base . '/' . $n . '/chats';
            if (!is_dir($chats)) {
                continue;
            }
            $files = [];
            foreach (@scandir($chats) ?: [] as $x) {
                if (str_ends_with($x, '.jsonl') && $x !== '' && $x[0] !== '.') {
                    $files[] = $x;
                }
            }
            sort($files, SORT_STRING);
            foreach (array_slice($files, 0, 8) as $x) {
                if (isset($hashes[self::peekHash($chats . '/' . $x) ?? "\0"])) {
                    $add($chats);
                    break;
                }
            }
        }
        return $found;
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    public function scan(int $nowSec): array
    {
        $roots = self::projectRoots($this->projectDir);
        if (!count($roots)) {
            return ['exists' => false, 'runs' => [], 'mains' => []];
        }
        try {
            return self::$lastGood = $this->scanRoots($roots, $nowSec);
        } catch (Throwable) {
            // ponytail: file terkunci/rusak → hasil bagus terakhir, bukan kantor kosong.
            return self::$lastGood ?? ['exists' => true, 'runs' => [], 'mains' => []];
        }
    }

    /** @param list<string> $roots @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    private function scanRoots(array $roots, int $nowSec): array
    {
        $cutoff = $nowSec - (int) $this->cfg['window_days'] * 86400;
        $mains = [];
        $runs = [];
        foreach ($roots as $root) {
            $r = $this->scanRoot($root, $cutoff);
            $mains = array_merge($mains, $r['mains']);
            $runs = array_merge($runs, $r['runs']);
        }
        usort($mains, static function ($a, $b): int {
            $au = $a['updated'];
            $bu = $b['updated'];
            if ($au !== $bu && is_string($au) && is_string($bu)) {
                $c = $au < $bu ? 1 : ($au > $bu ? -1 : 0);
                if ($c !== 0) {
                    return $c;
                }
            }
            return strcmp((string) $a['session'], (string) $b['session']);
        });
        $mains = array_slice($mains, 0, (int) $this->cfg['mains_max']);
        usort($runs, static fn($a, $b) => ($a['started'] <=> $b['started']) ?: strcmp((string) $a['id'], (string) $b['id']));
        return ['exists' => true, 'runs' => $runs, 'mains' => $mains];
    }

    /** @return array{mains:list<array<string,mixed>>,runs:list<array<string,mixed>>} */
    private function scanRoot(string $root, int $cutoff): array
    {
        $files = [];
        foreach (@scandir($root) ?: [] as $n) {
            if ($n === '' || $n[0] === '.' || strlen($n) <= 5) {
                continue;
            }
            if (!str_ends_with($n, '.jsonl') && !str_ends_with($n, '.json')) {
                continue;
            }
            $files[] = $root . '/' . $n;
        }
        $mainFiles = [];
        foreach ($files as $f) {
            $mt = KUtil::mtime($f);
            if ($mt >= $cutoff) {
                $mainFiles[] = [$f, $mt];
            }
        }
        usort($mainFiles, static fn($a, $b) => ($b[1] <=> $a[1]) ?: strcmp($a[0], $b[0]));
        $mainFiles = array_slice($mainFiles, 0, (int) $this->cfg['mains_max']);
        // ponytail: format Gemini tak mencatat cwd per sesi → tak ada saring cwd; roots sudah terverifikasi.
        $mains = [];
        foreach ($mainFiles as [$f]) {
            $s = $this->state($f);
            $mains[] = $this->view($s) + [
                'session' => $s['sid'] !== null ? $s['sid'] : self::stripExt(self::basename($f)),
                'provider' => 'gemini',
                'agentType' => 'general-purpose',
                'description' => $s['summary'] !== '' ? KUtil::safeLine($s['summary'], 140) : '',
            ];
        }
        $runs = [];
        $subs = [];
        foreach (@scandir($root) ?: [] as $n) {
            if ($n === '' || $n[0] === '.') {
                continue;
            }
            $subs[] = $n;
        }
        sort($subs, SORT_STRING);
        foreach ($subs as $n) {
            $d = $root . '/' . $n;
            if (!is_dir($d)) {
                continue;
            }
            foreach (self::walkSession($d) as $cf) {
                if (KUtil::mtime($cf) < $cutoff) {
                    continue;
                }
                $s = $this->state($cf);
                if ($s['started'] === null) {
                    continue; // belum ada pesan bertanggal
                }
                $sid = $s['sid'] !== null ? $s['sid'] : self::stripExt(self::basename($cf));
                $runs[] = $this->view($s) + [
                    'id' => $sid,
                    'session' => $n,
                    'provider' => 'gemini',
                    'agentType' => 'general-purpose',
                    'description' => $s['summary'] !== '' ? KUtil::safeLine($s['summary'], 140) : '',
                    'parentAgent' => $n,
                ];
            }
        }
        return ['mains' => $mains, 'runs' => $runs];
    }

    /** @return array<string,mixed> */
    private function state(string $file): array
    {
        $st = @stat($file);
        $size = $st !== false ? (int) $st['size'] : 0;
        $mtimeMs = $st !== false ? (int) ($st['mtime'] * 1000) : 0;
        $head = self::headOf($file, min($size, self::HEAD_MAX));
        $m = self::$memo[$file] ?? null;
        $s = ($m !== null && $m['size'] === $size && $m['mtimeMs'] === $mtimeMs && $m['head'] === $head) ? $m['s'] : null;
        $cacheFile = $this->cacheDir !== null ? $this->cacheDir . '/g-' . md5($file) . '.json' : null;
        if ($s === null && $cacheFile !== null && is_file($cacheFile)) {
            try {
                $c = json_decode((string) @file_get_contents($cacheFile), true);
                // ponytail: $rewindTo/$set.messages membuat append-offset tak valid → parse penuh bila berubah.
                if (self::isPlainObj($c) && ($c['v'] ?? null) === self::CACHE_V && ($c['size'] ?? null) === $size && ($c['mtimeMs'] ?? null) === $mtimeMs && ($c['head'] ?? null) === $head) {
                    $s = $c['s'];
                }
            } catch (Throwable) {
                $s = null;
            }
        }
        if ($s === null || !self::isPlainObj($s)) {
            $s = self::fresh();
            $raw = @file_get_contents($file);
            if (is_string($raw)) {
                if (str_ends_with($file, '.json')) {
                    try {
                        $rec = json_decode($raw, true);
                        if (self::isPlainObj($rec) && isset($rec['messages']) && is_array($rec['messages'])) {
                            $this->consume($s, ['sessionId' => $rec['sessionId'] ?? null, 'projectHash' => $rec['projectHash'] ?? null, 'startTime' => $rec['startTime'] ?? null, 'lastUpdated' => $rec['lastUpdated'] ?? null, 'kind' => $rec['kind'] ?? null, 'summary' => $rec['summary'] ?? null]);
                            $msgs = array_values(array_filter($rec['messages'], static fn($x) => self::isPlainObj($x) ? true : false));
                            /** @var list<array<string,mixed>> $msgs */
                            foreach (self::foldRows($msgs) as $row) {
                                $this->consume($s, $row);
                            }
                        }
                    } catch (Throwable) {
                        /* berkas rusak: ringkasan kosong */
                    }
                } else {
                    $rows = [];
                    foreach (explode("\n", $raw) as $line) {
                        if (trim($line) === '') {
                            continue;
                        }
                        $row = json_decode($line, true);
                        if (self::isPlainObj($row)) {
                            $rows[] = $row;
                        }
                        /* baris rusak dilewati */
                    }
                    foreach (self::foldRows($rows) as $row) {
                        $this->consume($s, $row);
                    }
                }
            }
            self::closeSeg($s);
            if ($cacheFile !== null && (is_dir(dirname($cacheFile)) || @mkdir(dirname($cacheFile), 0775, true))) {
                @file_put_contents($cacheFile, (string) json_encode(['v' => self::CACHE_V, 'size' => $size, 'mtimeMs' => $mtimeMs, 'head' => $head, 's' => $s], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
            }
        }
        self::$memo[$file] = ['size' => $size, 'mtimeMs' => $mtimeMs, 'head' => $head, 's' => $s];
        return $s;
    }

    /** @return array<string,mixed> */
    public function summarize(string $file): array
    {
        return $this->view($this->state($file));
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function view(array $s): array
    {
        return [
            'started' => $s['started'], 'updated' => $s['updated'], 'tools' => $s['tools'],
            'tokens' => (int) $s['tokens']['in'] + (int) $s['tokens']['out'] + (int) $s['tokens']['cache'],
            'events' => $s['events'], 'lastKind' => $s['lastKind'], 'limit' => $s['limit'], 'files' => $s['files'],
            'todos' => $s['todos'], 'todosAt' => $s['todosAt'], 'todoSource' => $s['todoSource'],
            'segs' => $s['segs'], 'stops' => [],
        ];
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $row */
    private function consume(array &$s, array $row): void
    {
        if (array_key_exists('$rewindTo', $row) || array_key_exists('$set', $row)) {
            return; // kontrol sudah dilipat di foldRows
        }
        if (isset($row['sessionId']) && is_string($row['sessionId']) && isset($row['projectHash']) && is_string($row['projectHash']) && !array_key_exists('type', $row)) {
            // ponytail: baris metadata — hanya id/judul/waktu, bukan aktivitas.
            if ($s['sid'] === null && $row['sessionId'] !== '') {
                $s['sid'] = $row['sessionId'];
            }
            if (isset($row['summary']) && is_string($row['summary']) && trim($row['summary']) !== '') {
                $s['summary'] = $row['summary'];
            }
            if ($s['started'] === null && isset($row['startTime']) && is_string($row['startTime']) && KUtil::tsMs($row['startTime']) !== null) {
                $s['started'] = $row['startTime'];
            }
            if (isset($row['lastUpdated']) && is_string($row['lastUpdated']) && KUtil::tsMs($row['lastUpdated']) !== null) {
                $s['updated'] = $row['lastUpdated'];
            }
            return;
        }
        if (!isset($row['id']) || !is_string($row['id']) || !isset($row['type']) || !is_string($row['type'])) {
            return; // baris tak dikenal diabaikan
        }
        $t = isset($row['timestamp']) && is_string($row['timestamp']) && KUtil::tsMs($row['timestamp']) !== null ? $row['timestamp'] : '';
        if ($row['type'] === 'user') {
            // ponytail: prompt user tidak pernah ditampilkan — hanya penanda.
            if ($t !== '') {
                if ($s['started'] === null) {
                    $s['started'] = $t;
                }
                $s['updated'] = $t;
                self::segGap($s, $t, (int) $this->cfg['cooldown']);
                $this->push($s, $t, 'user', 'Instruksi dari user', null);
            }
            $s['lastKind'] = 'user';
            return;
        }
        if ($row['type'] !== 'gemini' && $row['type'] !== 'model') {
            return; // info/error/warning: dilewati total.
        }
        if ($t === '') {
            return;
        }
        if ($s['started'] === null) {
            $s['started'] = $t;
        }
        $s['updated'] = $t;
        self::segGap($s, $t, (int) $this->cfg['cooldown']);
        $tk = self::isPlainObj($row['tokens'] ?? null) ? $row['tokens'] : [];
        $s['tokens']['in'] += self::int($tk['input'] ?? null);
        $s['tokens']['out'] += self::int($tk['output'] ?? null);
        $s['tokens']['cache'] += self::int($tk['cached'] ?? null) + self::int($tk['thoughts'] ?? null) + self::int($tk['tool'] ?? null);
        $hasTool = false;
        $hasText = false;
        foreach (is_array($row['toolCalls'] ?? null) && array_is_list($row['toolCalls']) ? $row['toolCalls'] : [] as $tc) {
            if (!self::isPlainObj($tc)) {
                continue;
            }
            $name = isset($tc['name']) && is_string($tc['name']) ? $tc['name'] : '?';
            $args = self::isPlainObj($tc['args'] ?? null) ? $tc['args'] : [];
            // ponytail: HASIL tool (tc.result) TIDAK PERNAH dibaca — hanya nama & argumen path.
            $hasTool = true;
            $s['tools']++;
            [$text, $p] = $this->describeTool($name, $args);
            if ($p !== null) {
                $s['files'] = array_values(array_filter($s['files'], static fn($f) => $f !== $p));
                $s['files'][] = $p;
                if (count($s['files']) > self::FILES_KEEP) {
                    array_shift($s['files']);
                }
            }
            $this->push($s, $t, 'tool', $text, $name);
        }
        foreach (self::textsOf($row['content'] ?? null) as $txt) {
            $hasText = true;
            if (preg_match('/(usage limit|rate limit|limit reached|resets? (at|in))/i', $txt) && mb_strlen($txt) < 400) {
                $s['limit'] = KUtil::clip(KUtil::redact($txt), 200);
            }
            $this->push($s, $t, 'text', KUtil::safeLine($txt, 180), null);
        }
        if ($hasTool) {
            $s['lastKind'] = 'tool';
            $s['limit'] = null;
        } elseif ($hasText) {
            $s['lastKind'] = 'text';
        } elseif ($s['lastKind'] === null) {
            $s['lastKind'] = 'thinking';
        }
    }

    /** @param array<string,mixed> $s */
    private function push(array &$s, string $t, string $kind, string $text, ?string $tool): void
    {
        if ($t === '') {
            return;
        }
        $s['events'][] = ['t' => $t, 'kind' => $kind, 'text' => $text, 'tool' => $tool];
        if (count($s['events']) > self::EVENTS_KEEP) {
            array_splice($s['events'], 0, count($s['events']) - self::EVENTS_KEEP);
        }
    }

    /** @param array<string,mixed> $args @return array{0:string,1:?string} */
    private function describeTool(string $name, array $args): array
    {
        $str = static fn($v): string => is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        $rel = fn($p): string => str_starts_with($p, $this->projectDir . '/') ? substr($p, strlen($this->projectDir) + 1) : self::basename($p);
        $f = null;
        foreach (['file_path', 'path', 'filePath', 'dir_path', 'absolute_path'] as $k) {
            if (isset($args[$k]) && is_string($args[$k]) && $args[$k] !== '') {
                $f = $rel($args[$k]);
                break;
            }
        }
        $withFile = $f !== null && in_array($name, ['read_file', 'read_many_files', 'write_file', 'replace', 'list_directory'], true);
        $text = match ($name) {
            'read_file', 'read_many_files' => 'Membaca ' . ($f ?? ''),
            'write_file' => 'Menulis ' . ($f ?? ''),
            'replace' => 'Mengubah ' . ($f ?? ''),
            'list_directory' => 'Melihat direktori ' . ($f ?? ''),
            // ponytail: argumen shell tidak pernah ditampilkan utuh — purpose saja.
            'run_shell_command', 'shell' => 'Menjalankan: ' . ($str(($args['description'] ?? null) ?: ($args['purpose'] ?? null)) !== '' ? $str(($args['description'] ?? null) ?: ($args['purpose'] ?? null)) : (self::firstToken($str($args['command'] ?? null)) . ' …')),
            'grep_search', 'grep', 'search' => "Mencari '" . KUtil::clip($str($args['pattern'] ?? null), 50) . "'",
            'glob' => 'Mencari file ' . KUtil::clip($str($args['pattern'] ?? null), 60),
            'web_fetch', 'web_search', 'google_web_search' => 'Riset web',
            'save_memory' => 'Menyimpan memori',
            'ask_user', 'question' => 'Bertanya ke user',
            'todo', 'todowrite' => 'Memperbarui daftar tugas',
            'task', 'subagent' => 'Mendelegasikan: ' . ($str($args['description'] ?? null) !== '' ? $str($args['description']) : 'subagent'),
            'update_topic' => 'Topik: ' . KUtil::clip($str($args['title'] ?? null), 60),
            // ponytail: nama tool bervariasi antar versi; tampil apa adanya.
            default => KUtil::clip($name, 60),
        };
        return [KUtil::oneLine($text, 160), $withFile ? $f : null];
    }

    /**
     * lipat kontrol JSONL: $rewindTo memotong pesan, $set.messages mengganti semua, $set lain menambal meta.
     * @param list<array<string,mixed>> $rows @return list<array<string,mixed>>
     */
    private static function foldRows(array $rows): array
    {
        $meta = null;
        $msgs = [];
        /** @var array<string,int> $byId */
        $byId = [];
        foreach ($rows as $row) {
            if (array_key_exists('$rewindTo', $row)) {
                $id = $row['$rewindTo'];
                $i = (is_string($id) && array_key_exists($id, $byId)) ? $byId[$id] : -1;
                $msgs = $i >= 0 ? array_slice($msgs, 0, $i) : [];
                $byId = [];
                foreach ($msgs as $j => $m) {
                    if (isset($m['id']) && is_string($m['id'])) {
                        $byId[$m['id']] = $j;
                    }
                }
                continue;
            }
            if (array_key_exists('$set', $row) && self::isPlainObj($row['$set'])) {
                $set = $row['$set'];
                if (isset($set['messages']) && is_array($set['messages'])) {
                    $msgs = array_values(array_filter($set['messages'], static fn($x) => self::isPlainObj($x) ? true : false));
                    $byId = [];
                    foreach ($msgs as $j => $m) {
                        if (isset($m['id']) && is_string($m['id'])) {
                            $byId[$m['id']] = $j;
                        }
                    }
                }
                if ($meta !== null) {
                    $meta = array_merge($meta, $set);
                } elseif (isset($set['sessionId']) && is_string($set['sessionId'])) {
                    $meta = array_merge([], $set);
                }
                continue;
            }
            if (isset($row['sessionId']) && is_string($row['sessionId']) && isset($row['projectHash']) && is_string($row['projectHash']) && !array_key_exists('type', $row)) {
                $meta = $meta === null ? array_merge([], $row) : array_merge($meta, $row);
                continue;
            }
            if (isset($row['id']) && is_string($row['id'])) {
                if (array_key_exists($row['id'], $byId)) {
                    $msgs[$byId[$row['id']]] = $row;
                } else {
                    $msgs[] = $row;
                    $byId[$row['id']] = count($msgs) - 1;
                }
            }
        }
        return $meta !== null ? array_merge([$meta], $msgs) : $msgs;
    }

    /** berkas sesi di bawah subdir (sarang subagent), rekursif, tanpa entri tersembunyi, terurut byte @return list<string> */
    private static function walkSession(string $dir): array
    {
        $out = [];
        $names = @scandir($dir);
        if ($names === false) {
            return $out;
        }
        $names = array_values(array_filter($names, static fn($x) => $x !== '' && $x[0] !== '.'));
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            $f = $dir . '/' . $n;
            if ((str_ends_with($n, '.jsonl') || str_ends_with($n, '.json')) && strlen($n) > 5) {
                if (is_file($f)) {
                    $out[] = $f;
                }
            } elseif (is_dir($f)) {
                foreach (self::walkSession($f) as $c) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    /** teks asisten: string langsung atau gabungan part {text}; tanpa itu → [] (thoughts tanpa event) @return list<string> */
    private static function textsOf(mixed $content): array
    {
        if (is_string($content)) {
            $t = trim($content);
            return $t === '' ? [] : [$t];
        }
        $out = [];
        foreach (self::values($content) as $p) {
            if (self::isPlainObj($p) && isset($p['text']) && is_string($p['text']) && trim($p['text']) !== '') {
                $out[] = trim($p['text']);
            }
        }
        return $out;
    }

    /** segmen kerja: dibuka pesan pertama; jeda > cooldown menutup lalu membuka baru; terakhir tetap terbuka. @param array<string,mixed> $s */
    private static function segGap(array &$s, string $t, int $cooldown): void
    {
        $ms = KUtil::tsMs($t);
        if (!count($s['segs'])) {
            $s['segs'][] = [$t, null];
            $s['lastMs'] = $ms;
            return;
        }
        if ($ms - $s['lastMs'] > $cooldown * 1000) {
            $s['segs'][count($s['segs']) - 1][1] = KUtil::isoMs((int) $s['lastMs']);
            $s['segs'][] = [$t, null];
            if (count($s['segs']) > self::SEGS_KEEP) {
                array_shift($s['segs']);
            }
        }
        $s['lastMs'] = $ms;
    }

    /** @param array<string,mixed> $s */
    private static function closeSeg(array &$s): void
    {
        if ($s['started'] !== null && !count($s['segs'])) {
            $s['segs'][] = [$s['started'], null];
        }
        unset($s['lastMs']); // ponytail: hanya penghitung jeda saat parse, bukan bagian cache abadi
    }

    private static function headOf(string $file, int $n): string
    {
        if (!($n > 0)) {
            return '';
        }
        $fd = @fopen($file, 'rb');
        if (!is_resource($fd)) {
            return '';
        }
        try {
            $b = fread($fd, $n);
            if (!is_string($b) || strlen($b) !== $n) {
                return '';
            }
            return md5($b);
        } catch (Throwable) {
            return '';
        } finally {
            fclose($fd);
        }
    }

    /** @return array<string,mixed> */
    private static function fresh(): array
    {
        return [
            'sid' => null, 'summary' => '', 'started' => null, 'updated' => null, 'tools' => 0,
            'tokens' => ['in' => 0, 'out' => 0, 'cache' => 0],
            'events' => [], 'lastKind' => null, 'limit' => null, 'files' => [], 'todos' => null, 'todosAt' => null,
            'todoSource' => null, 'segs' => [], 'lastMs' => 0,
        ];
    }

    private static function int(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        return (is_float($v) && is_finite($v)) ? (int) $v : 0;
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

    private static function basename(string $p, string $ext = ''): string
    {
        $b = basename($p);
        return ($ext !== '' && str_ends_with($b, $ext) && $b !== $ext) ? substr($b, 0, -strlen($ext)) : $b;
    }

    private static function stripExt(string $b): string
    {
        if (str_ends_with($b, '.jsonl')) {
            return substr($b, 0, -strlen('.jsonl'));
        }
        if (str_ends_with($b, '.json')) {
            return substr($b, 0, -strlen('.json'));
        }
        return $b;
    }

    private static function isPlainObj(mixed $v): bool
    {
        return is_array($v) && !array_is_list($v);
    }

    /** @return list<mixed> */
    private static function values(mixed $v): array
    {
        if (is_array($v) && array_is_list($v)) {
            return $v;
        }
        return self::isPlainObj($v) ? array_values($v) : [];
    }
}
