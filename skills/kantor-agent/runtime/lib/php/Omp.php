<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * Sesi Oh My Pi / omp (~/.omp/agent/sessions/<encoded-cwd>/*.jsonl) — read-only.
 * Isi tool_result TIDAK PERNAH dibaca. Port Node: lib/node/omp.mjs (dicek bin/parity.mjs).
 */
final class KOmp
{
    private const CACHE_V = 1;
    private const HEAD_MAX = 1024;
    private const EVENTS_KEEP = 40;
    private const SEGS_KEEP = 20;
    private const FILES_KEEP = 12;
    private const TODOS_KEEP = 40;

    private ?string $cacheDir;
    private static ?array $lastGood = null;
    // ponytail: dir ditemukan sekali per proses; pindah OMP_SESSIONS_DIR perlu restart server.
    private static ?string $dirMemo = null;
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
        $env = (string) getenv('OMP_SESSIONS_DIR');
        if ($env !== '') {
            return self::$dirMemo = rtrim($env, '/');
        }
        $home = (string) getenv('HOME');
        if ($home === '' && function_exists('posix_getpwuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        return self::$dirMemo = rtrim($home, '/') . '/.omp/agent/sessions';
    }

    // ponytail: encoder = oh-my-pi session-paths.ts (bukan munge claude);
    // data nyata mesin ini cocok semua: /home/nayaka/camat-trk→-camat-trk, /tmp→-tmp, $HOME→'-'.
    private static function canon(string $p): string
    {
        $r = @realpath($p);
        if (is_string($r) && $r !== '') {
            return $r;
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
        return '/' . implode('/', $parts);
    }

    private static function relName(string $prefix, string $rel): string
    {
        $enc = (string) preg_replace('#[/\\\\:]#', '-', $rel);
        if ($enc === '') {
            return $prefix;
        }
        return str_ends_with($prefix, '-') ? $prefix . $enc : $prefix . '-' . $enc;
    }

    private static function relative(string $from, string $to): string
    {
        if ($to === $from) {
            return '';
        }
        $prefix = rtrim($from, '/') . '/';
        if (str_starts_with($to, $prefix)) {
            return substr($to, strlen($prefix));
        }
        $fa = array_values(array_filter(explode('/', trim($from, '/')), static fn($x) => $x !== ''));
        $ta = array_values(array_filter(explode('/', trim($to, '/')), static fn($x) => $x !== ''));
        $i = 0;
        while ($i < count($fa) && $i < count($ta) && $fa[$i] === $ta[$i]) {
            $i++;
        }
        $up = array_fill(0, count($fa) - $i, '..');
        return implode('/', [...$up, ...array_slice($ta, $i)]);
    }

    private static function homeDir(): string
    {
        $home = (string) getenv('HOME');
        if ($home === '' && function_exists('posix_getpwuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        return $home !== '' ? $home : '/root';
    }

    private static function encodeBucket(string $cwd): string
    {
        $c = self::canon($cwd);
        $relH = self::relative(self::canon(self::homeDir()), $c);
        if ($relH === '' || (!str_starts_with($relH, '..') && !str_starts_with($relH, '../') && !str_starts_with($relH, '/'))) {
            return self::relName('-', $relH);
        }
        $relT = self::relative(self::canon((string) sys_get_temp_dir()), $c);
        if ($relT === '' || (!str_starts_with($relT, '..') && !str_starts_with($relT, '../') && !str_starts_with($relT, '/'))) {
            return self::relName('-tmp', $relT);
        }
        $t = ltrim($c, "/\\");
        return '--' . ((string) preg_replace('#[/\\\\:]#', '-', $t)) . '--';
    }

    // judul/header tanpa parse penuh: 4 KiB pertama cukup (slot title + header di awal)
    private static function headerCwd(string $file): ?string
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
            foreach (explode("\n", $b) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                if (self::isPlainObj($row) && ($row['type'] ?? null) === 'session') {
                    return isset($row['cwd']) && is_string($row['cwd']) ? $row['cwd'] : null;
                }
                if (self::isPlainObj($row) && ($row['type'] ?? null) === 'message') {
                    break; // header tak ketemu sebelum pesan
                }
            }
        } catch (Throwable) {
            /* abaikan */
        } finally {
            fclose($fd);
        }
        return null;
    }

    private static function projectRoot(string $projectDir): ?string
    {
        $base = self::dir();
        $exact = $base . '/' . self::encodeBucket($projectDir);
        if (is_dir($exact)) {
            return $exact;
        }
        // ponytail: bucket warisan (ejaan lama) atau cwd pindah: cocokkan header cwd, bukan nama.
        $want = [rtrim($projectDir, '/'), self::canon($projectDir)];
        $names = @scandir($base);
        if ($names === false) {
            return null;
        }
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            if ($n === '' || $n[0] === '.') {
                continue;
            }
            $d = $base . '/' . $n;
            if (!is_dir($d)) {
                continue;
            }
            $files = [];
            foreach (@scandir($d) ?: [] as $x) {
                if (str_ends_with($x, '.jsonl') && $x !== '.jsonl' && !str_starts_with($x, '.')) {
                    $files[] = $x;
                }
            }
            sort($files, SORT_STRING);
            foreach (array_slice($files, 0, 8) as $x) {
                $cwd = self::headerCwd($d . '/' . $x);
                if ($cwd !== null && in_array(rtrim($cwd, '/'), $want, true)) {
                    return $d;
                }
            }
        }
        return null;
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    public function scan(int $nowSec): array
    {
        $root = self::projectRoot($this->projectDir);
        if ($root === null) {
            return ['exists' => false, 'runs' => [], 'mains' => []];
        }
        try {
            return self::$lastGood = $this->scanRoot($root, $nowSec);
        } catch (Throwable) {
            // ponytail: file terkunci/rusak → hasil bagus terakhir, bukan kantor kosong.
            return self::$lastGood ?? ['exists' => true, 'runs' => [], 'mains' => []];
        }
    }

    /** @return array{exists:bool,runs:list<array<string,mixed>>,mains:list<array<string,mixed>>} */
    private function scanRoot(string $root, int $nowSec): array
    {
        $cutoff = $nowSec - (int) $this->cfg['window_days'] * 86400;
        $want = [rtrim($this->projectDir, '/'), self::canon($this->projectDir)];
        $mainFiles = [];
        foreach (KUtil::globDir($root, '.jsonl') as $f) {
            $mt = KUtil::mtime($f);
            if ($mt >= $cutoff) {
                $mainFiles[] = [$f, $mt];
            }
        }
        usort($mainFiles, static fn($a, $b) => ($b[1] <=> $a[1]) ?: strcmp($a[0], $b[0]));
        // ponytail: bucket bisa campuran (migrasi); saring header cwd yang tak cocok.
        $mainFiles = array_values(array_filter($mainFiles, function ($pair) use ($want) {
            $c = $this->state($pair[0])['cwd'];
            return $c === null || in_array(rtrim($c, '/'), $want, true);
        }));
        $mainFiles = array_slice($mainFiles, 0, (int) $this->cfg['mains_max']);
        $mains = [];
        foreach ($mainFiles as [$f]) {
            $s = $this->state($f);
            $mains[] = $this->view($s) + [
                'session' => $s['sid'] !== null ? $s['sid'] : self::idFromName($f),
                'provider' => 'omp',
                'agentType' => 'general-purpose',
                'description' => $s['title'] !== '' ? KUtil::safeLine($s['title'], 140) : '',
            ];
        }

        $runs = [];
        foreach ($mainFiles as [$f]) {
            $parentSid = $this->state($f)['sid'] ?? self::idFromName($f);
            $sub = substr($f, 0, -strlen('.jsonl'));
            if (!is_dir($sub)) {
                continue;
            }
            foreach (self::walkJsonl($sub) as $cf) {
                if (KUtil::mtime($cf) < $cutoff) {
                    continue;
                }
                $s = $this->state($cf);
                if ($s['started'] === null) {
                    continue; // belum ada pesan bertanggal
                }
                $agent = self::basename($cf, '.jsonl');
                $runs[] = $this->view($s) + [
                    'id' => $s['sid'] !== null ? $s['sid'] : $agent,
                    'session' => $parentSid,
                    'provider' => 'omp',
                    'agentType' => KUtil::clip($agent, 40) !== '' ? KUtil::clip($agent, 40) : 'general-purpose',
                    'description' => $s['title'] !== '' ? KUtil::safeLine($s['title'], 140) : '',
                    'parentAgent' => $parentSid,
                ];
            }
        }
        usort($runs, static fn($a, $b) => ($a['started'] <=> $b['started']) ?: strcmp((string) $a['id'], (string) $b['id']));
        return ['exists' => true, 'runs' => $runs, 'mains' => $mains];
    }

    /** @return array<string,mixed> */
    private function state(string $file): array
    {
        $st = @stat($file);
        $size = $st !== false ? (int) $st['size'] : 0;
        $mtimeMs = $st !== false ? (int) ($st['mtime'] * 1000) : 0;
        $m = self::$memo[$file] ?? null;
        $s = ($m !== null && $m['size'] === $size && $m['mtimeMs'] === $mtimeMs && $m['head'] === self::headOf($file, $m['s']['headLen'])) ? $m['s'] : null;
        $cacheFile = $this->cacheDir !== null ? $this->cacheDir . '/p-' . md5($file) . '.json' : null;
        if ($s === null && $cacheFile !== null && is_file($cacheFile)) {
            $c = json_decode((string) @file_get_contents($cacheFile), true);
            $s = is_array($c) ? $c : null;
        }
        if (!is_array($s) || ($s['v'] ?? null) !== self::CACHE_V || !isset($s['offset']) || !is_numeric($s['offset'])
            || (int) $s['offset'] < 0 || (int) $s['offset'] > $size || !isset($s['headLen']) || !is_numeric($s['headLen'])
            || (int) $s['headLen'] < 0 || (int) $s['headLen'] > $size || self::headOf($file, (int) $s['headLen']) !== ($s['head'] ?? null)) {
            $s = self::fresh();
        }
        if ((int) $s['offset'] < $size) {
            $fd = @fopen($file, 'rb');
            if (is_resource($fd)) {
                try {
                    fseek($fd, (int) $s['offset']);
                    $pos = (int) $s['offset'];
                    $carry = '';
                    while (!feof($fd)) {
                        $chunk = fread($fd, 1 << 20);
                        if (!is_string($chunk) || $chunk === '') {
                            break;
                        }
                        $data = $carry . $chunk;
                        $start = 0;
                        while (($nl = strpos($data, "\n", $start)) !== false) {
                            $line = substr($data, $start, $nl - $start + 1);
                            $pos += strlen($line);
                            $start = $nl + 1;
                            $row = json_decode($line, true);
                            if (self::isPlainObj($row)) {
                                $this->consume($s, $row);
                            }
                        }
                        $carry = substr($data, $start);
                    }
                    $s['offset'] = $pos; // baris terakhir tanpa \n belum lengkap — dibaca lagi nanti
                    $s['headLen'] = min($size, self::HEAD_MAX);
                    $s['head'] = self::headOf($file, $s['headLen']);
                } catch (Throwable) {
                    /* file hilang/terkunci: pakai ringkasan yang ada */
                } finally {
                    fclose($fd);
                }
                if ($cacheFile !== null && (is_dir(dirname($cacheFile)) || @mkdir(dirname($cacheFile), 0775, true))) {
                    @file_put_contents($cacheFile, (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
                }
            }
        }
        self::$memo[$file] = ['size' => $size, 'mtimeMs' => $mtimeMs, 's' => $s, 'head' => $s['head']];
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
        $type = $row['type'] ?? '';
        if ($type === 'session') {
            // ponytail: baris header — hanya id/cwd (+ judul bila ada), bukan aktivitas.
            if ($s['sid'] === null && isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
                $s['sid'] = $row['id'];
            }
            if ($s['cwd'] === null && isset($row['cwd']) && is_string($row['cwd']) && $row['cwd'] !== '') {
                $s['cwd'] = $row['cwd'];
            }
            if (isset($row['title']) && is_string($row['title']) && trim($row['title']) !== '') {
                $s['title'] = $row['title'];
            }
            return;
        }
        if (($type === 'title' || $type === 'title_change') && isset($row['title']) && is_string($row['title'])) {
            // ponytail: judul awal sering kosong; yang terakhir tak-kosong menang.
            if (trim($row['title']) !== '') {
                $s['title'] = $row['title'];
            }
            return;
        }
        if ($type === 'model_usage') {
            // ponytail: akuntansi model di luar transkrip — hanya angka, tanpa event.
            $u = self::isPlainObj($row['usage'] ?? null) ? $row['usage'] : [];
            $s['tokens']['in'] += self::int($u['input'] ?? null);
            $s['tokens']['out'] += self::int($u['output'] ?? null);
            $s['tokens']['cache'] += self::int($u['cacheRead'] ?? null) + self::int($u['cacheWrite'] ?? null);
            return;
        }
        if ($type !== 'message') {
            return; // model_change/mode_change/custom/dll: dilewati total.
        }
        $t = isset($row['timestamp']) && is_string($row['timestamp']) && KUtil::tsMs($row['timestamp']) !== null ? $row['timestamp'] : '';
        $msg = $row['message'] ?? null;
        if (!self::isPlainObj($msg)) {
            return;
        }
        $role = $msg['role'] ?? '';
        if ($t !== '') {
            if ($s['started'] === null) {
                $s['started'] = $t;
            }
            $s['updated'] = $t;
        }
        if ($role === 'toolResult' || $role === 'developer') {
            // ponytail: HASIL tool & pengingat sistem TIDAK PERNAH dibaca.
            if ($s['lastKind'] !== 'handback' && $s['lastKind'] !== 'final') {
                $s['lastKind'] = 'tool';
            }
            return;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            return; // bashExecution/fileMention/dll: isi tak dibaca.
        }
        if ($t !== '') {
            if (!count($s['segs'])) {
                $s['segs'][] = [$t, null];
            }
            $s['updated'] = $t;
            if ($role === 'user' || $role === 'assistant') {
                $n = count($s['segs']);
                $last = $s['segs'][$n - 1];
                if ($last !== null && $last[1] !== null) {
                    if (KUtil::tsMs($t) - KUtil::tsMs($last[1]) <= (int) $this->cfg['cooldown'] * 1000) {
                        $s['segs'][$n - 1][1] = null;
                    } else {
                        $s['segs'][] = [$t, null];
                        if (count($s['segs']) > self::SEGS_KEEP) {
                            array_shift($s['segs']);
                        }
                    }
                }
            }
        }
        if ($role === 'user') {
            // ponytail: prompt user tidak pernah ditampilkan — hanya penanda.
            $this->push($s, $t, 'user', 'Instruksi dari user', null);
            $s['lastKind'] = 'user';
            return;
        }
        $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
        $usage = self::isPlainObj($msg['usage'] ?? null) ? $msg['usage'] : [];
        if ($id !== '' && $id !== $s['lastMsgId']) {
            $s['tokens']['in'] += self::int($usage['input'] ?? null);
            $s['tokens']['out'] += self::int($usage['output'] ?? null);
            $s['tokens']['cache'] += self::int($usage['cacheRead'] ?? null) + self::int($usage['cacheWrite'] ?? null);
            $s['lastMsgId'] = $id;
        }
        $hasTool = false;
        $hasText = false;
        $handback = false;
        foreach (self::values($msg['content'] ?? null) as $b) {
            if (!self::isPlainObj($b)) {
                continue;
            }
            $bt = $b['type'] ?? '';
            if ($bt === 'toolCall') {
                $hasTool = true;
                $name = isset($b['name']) && is_string($b['name']) ? $b['name'] : '?';
                $inp = self::isPlainObj($b['arguments'] ?? null) ? $b['arguments'] : [];
                $s['tools']++;
                [$text, $p] = $this->describeTool($name, $inp);
                if ($p !== null && $name === 'read') {
                    $s['files'] = array_values(array_filter($s['files'], static fn($f) => $f !== $p));
                    $s['files'][] = $p;
                    if (count($s['files']) > self::FILES_KEEP) {
                        array_shift($s['files']);
                    }
                }
                $this->todo($s, $name, $inp, $t);
                if ($name === 'yield') {
                    $handback = true;
                }
                $this->push($s, $t, 'tool', $text, $name);
            } elseif ($bt === 'text') {
                $txt = isset($b['text']) && is_string($b['text']) ? trim($b['text']) : '';
                if ($txt === '') {
                    continue;
                }
                $hasText = true;
                if (preg_match('/(usage limit|rate limit|limit reached|resets? (at|in))/i', $txt) && mb_strlen($txt) < 400) {
                    $s['limit'] = KUtil::clip(KUtil::redact($txt), 200);
                }
                $this->push($s, $t, 'text', KUtil::safeLine($txt, 180), null);
            }
            // thinking: tanpa event.
        }
        $stop = isset($msg['stopReason']) && is_string($msg['stopReason']) ? $msg['stopReason'] : '';
        if ($handback) {
            $s['lastKind'] = 'handback';
        } elseif ($hasTool) {
            $s['lastKind'] = 'tool';
        } elseif ($hasText) {
            $s['lastKind'] = $stop === 'stop' ? 'final' : 'text';
        } elseif ($s['lastKind'] === null) {
            $s['lastKind'] = 'thinking';
        }
        if ($hasTool) {
            $s['limit'] = null;
        }
        $n = count($s['segs']);
        if ($stop === 'stop' && $t !== '' && $n && $s['segs'][$n - 1][1] === null) {
            $s['segs'][$n - 1][1] = $t;
        }
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $inp */
    private function todo(array &$s, string $name, array $inp, string $t): void
    {
        if ($name !== 'todo') {
            return;
        }
        $items = $inp['items'] ?? null;
        if (is_string($items)) {
            $d = json_decode($items, true);
            if (!is_array($d)) {
                return;
            }
            $items = $d;
        }
        if (!is_array($items) || !count($items)) {
            return;
        }
        $out = [];
        foreach ($items as $td) {
            if (!self::isPlainObj($td)) {
                continue;
            }
            $text = isset($td['content']) && is_string($td['content']) ? $td['content']
                : (isset($td['task_description']) && is_string($td['task_description']) ? $td['task_description']
                : (isset($td['subject']) && is_string($td['subject']) ? $td['subject'] : ''));
            if (trim($text) === '') {
                continue;
            }
            $out[] = ['text' => KUtil::safeLine($text, 120), 'status' => self::todoStatus($td['status'] ?? $td['task_status'] ?? null)];
            if (count($out) >= self::TODOS_KEEP) {
                break;
            }
        }
        if (!count($out)) {
            return;
        }
        $s['todos'] = $out;
        $s['todosAt'] = $t;
        $s['todoSource'] = 'TodoWrite';
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

    /** @param array<string,mixed> $in @return array{0:string,1:?string} */
    private function describeTool(string $name, array $in): array
    {
        $str = static fn($v): string => is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        $rel = fn($p): string => str_starts_with($p, $this->projectDir . '/') ? substr($p, strlen($this->projectDir) + 1) : basename($p);
        $p = ($name === 'read' && isset($in['path']) && is_string($in['path']) && $in['path'] !== '') ? $rel($in['path']) : null;
        $text = match ($name) {
            'read' => 'Membaca ' . (isset($in['path']) && is_string($in['path']) ? $rel($in['path']) : ''),
            // ponytail: argumen shell tidak pernah ditampilkan — intent saja.
            'bash' => 'Menjalankan: ' . ($str($in['i'] ?? null) !== '' ? $str($in['i']) : self::firstToken($str($in['command'] ?? null)) . ' …'),
            'grep' => "Mencari '" . KUtil::clip($str($in['pattern'] ?? null), 50) . "'",
            'glob' => 'Mencari file ' . KUtil::clip($str($in['path'] ?? $in['pattern'] ?? null), 60),
            'task' => 'Mendelegasikan: ' . ($str($in['i'] ?? null) !== '' ? $str($in['i']) : 'subagent'),
            'hub' => 'Mengelola subagent',
            'ask' => 'Bertanya ke user',
            'todo' => 'Memperbarui daftar tugas',
            'yield' => 'Menyerahkan laporan',
            default => KUtil::clip($name, 60),
        };
        return [KUtil::oneLine($text, 160), $p];
    }

    /** @return list<string> */
    private static function walkJsonl(string $dir): array
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
            if (str_ends_with($n, '.jsonl') && strlen($n) > strlen('.jsonl')) {
                if (is_file($f)) {
                    $out[] = $f;
                }
            } elseif (is_dir($f)) {
                foreach (self::walkJsonl($f) as $c) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    private static function idFromName(string $f): string
    {
        $b = self::basename($f, '.jsonl');
        $i = strrpos($b, '_');
        return ($i !== false && $i < strlen($b) - 1) ? substr($b, $i + 1) : $b;
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
            'v' => self::CACHE_V, 'offset' => 0, 'headLen' => 0, 'head' => '', 'sid' => null, 'cwd' => null,
            'title' => '', 'started' => null, 'updated' => null, 'tools' => 0,
            'tokens' => ['in' => 0, 'out' => 0, 'cache' => 0], 'lastMsgId' => null,
            'events' => [], 'lastKind' => null, 'limit' => null, 'files' => [], 'todos' => null, 'todosAt' => null,
            'todoSource' => null, 'segs' => [],
        ];
    }

    private static function todoStatus(mixed $v): string
    {
        return in_array($v, ['pending', 'in_progress', 'completed'], true) ? $v : 'pending';
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
