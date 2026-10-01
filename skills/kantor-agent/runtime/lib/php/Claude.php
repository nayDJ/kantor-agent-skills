<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * Sesi Claude Code (~/.claude/projects/<munged-path>/*.jsonl) — read-only.
 * Isi tool_result TIDAK PERNAH dibaca. Port Node: lib/node/claude.mjs (dicek bin/parity.mjs).
 */
final class KClaude
{
    private const CACHE_V = 1;
    private const HEAD_MAX = 1024;
    private const EVENTS_KEEP = 40;
    private const SEGS_KEEP = 20;
    private const STOPS_KEEP = 80;
    private const FILES_KEEP = 12;
    private const TODOS_KEEP = 40;

    private ?string $cacheDir;
    private static ?array $lastGood = null;
    // ponytail: dir ditemukan sekali per proses; pindah CLAUDE_CONFIG_DIR perlu restart server.
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
        $env = (string) getenv('CLAUDE_CONFIG_DIR');
        if ($env !== '') {
            return self::$dirMemo = rtrim($env, '/');
        }
        $home = (string) getenv('HOME');
        if ($home === '' && function_exists('posix_getpwuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        return self::$dirMemo = rtrim($home, '/') . '/.claude';
    }

    private static function munge(string $p): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9]/', '-', $p);
    }

    private static function projectRoot(string $projectDir): ?string
    {
        $base = self::dir() . '/projects';
        $exact = $base . '/' . self::munge($projectDir);
        if (is_dir($exact)) {
            return $exact;
        }
        // ponytail: upstream tidak trim trailing slash ("/a/" → "-a-"); fallback longgar samakan lipatan '-' ujung.
        $loose = preg_replace('/^-+|-+$/', '', self::munge($projectDir));
        $names = @scandir($base);
        if ($names === false) {
            return null;
        }
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            if (preg_replace('/^-+|-+$/', '', $n) !== $loose) {
                continue;
            }
            $c = $base . '/' . $n;
            if (is_dir($c)) {
                return $c;
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
        $mainFiles = [];
        foreach (KUtil::globDir($root, '.jsonl') as $f) {
            $mt = KUtil::mtime($f);
            if ($mt >= $cutoff) {
                $mainFiles[] = [$f, $mt];
            }
        }
        usort($mainFiles, static fn($a, $b) => ($b[1] <=> $a[1]) ?: strcmp($a[0], $b[0]));
        $mainFiles = array_slice($mainFiles, 0, (int) $this->cfg['mains_max']);
        // ponytail: format Claude tidak menyimpan title → mains selalu general-purpose tanpa deskripsi (kontrak modern wajib isi).
        $mains = [];
        foreach ($mainFiles as [$f]) {
            $mains[] = $this->summarize($f) + ['session' => self::basename($f, '.jsonl'), 'agentType' => 'general-purpose', 'description' => ''];
        }

        $metas = [];
        foreach (KUtil::globDir($root, '') as $d) {
            if (!is_dir($d)) {
                continue;
            }
            foreach (KUtil::globDir($d . '/subagents', '.meta.json') as $m) {
                $metas[] = [$m, self::basename($d)];
            }
            foreach (KUtil::globDir($d . '/subagents/workflows', '') as $wf) {
                foreach (KUtil::globDir($wf, '.meta.json') as $m) {
                    $metas[] = [$m, self::basename($d)];
                }
            }
        }
        usort($metas, static fn($a, $b) => strcmp($a[0], $b[0]));
        $runs = [];
        foreach ($metas as [$metaFile, $session]) {
            $jsonl = substr($metaFile, 0, -strlen('.meta.json')) . '.jsonl';
            $name = self::basename($jsonl, '.jsonl');
            if (!str_starts_with($name, 'agent-') || !is_file($jsonl) || KUtil::mtime($jsonl) < $cutoff) {
                continue;
            }
            $meta = json_decode((string) @file_get_contents($metaFile), true);
            $meta = self::isPlainObj($meta) ? $meta : [];
            $type = isset($meta['agentType']) && is_string($meta['agentType']) && trim($meta['agentType']) !== ''
                ? KUtil::clip(trim($meta['agentType']), 40) : 'general-purpose';
            $desc = isset($meta['description']) && is_string($meta['description']) ? KUtil::safeLine($meta['description'], 140) : '';
            $sum = $this->summarize($jsonl);
            if ($sum['started'] === null) {
                continue; // belum ada baris bertanggal
            }
            $runs[] = $sum + [
                'id' => substr($name, 6),
                'session' => $session,
                'agentType' => $type,
                'description' => $desc,
                'parentAgent' => isset($meta['parentAgentId']) && is_string($meta['parentAgentId']) ? $meta['parentAgentId'] : null,
            ];
        }
        usort($runs, static fn($a, $b) => ($a['started'] <=> $b['started']) ?: strcmp((string) $a['id'], (string) $b['id']));
        return ['exists' => true, 'runs' => $runs, 'mains' => $mains];
    }

    /** @return array<string,mixed> */
    public function summarize(string $file): array
    {
        $st = @stat($file);
        $size = $st !== false ? (int) $st['size'] : 0;
        $mtimeMs = $st !== false ? (int) ($st['mtime'] * 1000) : 0;
        $m = self::$memo[$file] ?? null;
        $s = ($m !== null && $m['size'] === $size && $m['mtimeMs'] === $mtimeMs && $m['head'] === self::headOf($file, $m['s']['headLen'])) ? $m['s'] : null;
        $cacheFile = $this->cacheDir !== null ? $this->cacheDir . '/c-' . md5($file) . '.json' : null;
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
        return [
            'started' => $s['started'], 'updated' => $s['updated'], 'tools' => $s['tools'],
            'tokens' => (int) $s['tokens']['in'] + (int) $s['tokens']['out'] + (int) $s['tokens']['cache'],
            'events' => $s['events'], 'lastKind' => $s['lastKind'], 'limit' => $s['limit'], 'files' => $s['files'],
            'todos' => $s['todos'], 'todosAt' => $s['todosAt'], 'todoSource' => $s['todoSource'],
            'segs' => $s['segs'], 'stops' => $s['stops'],
        ];
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $row */
    private function consume(array &$s, array $row): void
    {
        $type = $row['type'] ?? '';
        $t = isset($row['timestamp']) && is_string($row['timestamp']) && KUtil::tsMs($row['timestamp']) !== null ? $row['timestamp'] : '';
        $msg = $row['message'] ?? null;
        if ($type !== 'user' && $type !== 'assistant') {
            return; // system/ringkasan: dilewati total.
        }
        if ($t !== '') {
            if ($s['started'] === null) {
                $s['started'] = $t;
            }
            $s['updated'] = $t;
        }
        if (!is_array($msg)) {
            return;
        }
        $content = $msg['content'] ?? '';
        // segmen kerja: dibuka baris pertama, ditutup jawaban akhir (end_turn), dibuka lagi bila ada pesan baru
        if ($t !== '') {
            $n = count($s['segs']);
            $last = $n ? $s['segs'][$n - 1] : null;
            if ($last === null) {
                $s['segs'][] = [$t, null];
            } elseif ($last[1] !== null && ($type === 'assistant' || is_string($content))) {
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
        if ($type === 'user') {
            if (is_string($content)) {
                // ponytail: prompt user tidak pernah ditampilkan — hanya penanda.
                if (self::isEmpty($row['isMeta'] ?? null) && !str_starts_with(ltrim($content), '<') && (($row['isSidechain'] ?? false) === false)) {
                    $this->push($s, $t, 'user', 'Instruksi dari user', null);
                }
                $s['lastKind'] = 'user';
            } elseif (is_array($content) && $s['lastKind'] !== 'handback') {
                $s['lastKind'] = 'result'; // tool_result & lampiran: privasi — isi tidak pernah dibaca.
            }
            return;
        }
        $id = isset($msg['id']) && is_string($msg['id']) ? $msg['id'] : '';
        if ($id !== '' && $id !== $s['lastMsgId'] && is_array($msg['usage'] ?? null)) {
            $u = $msg['usage'];
            $s['tokens']['in'] += self::int($u['input_tokens'] ?? null);
            $s['tokens']['out'] += self::int($u['output_tokens'] ?? null);
            $s['tokens']['cache'] += self::int($u['cache_read_input_tokens'] ?? null) + self::int($u['cache_creation_input_tokens'] ?? null);
            $s['lastMsgId'] = $id;
        }
        $hasTool = false;
        $hasText = false;
        $handback = false;
        foreach (self::values($content) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $bt = $b['type'] ?? '';
            if ($bt === 'tool_use') {
                $hasTool = true;
                $name = isset($b['name']) && is_string($b['name']) ? $b['name'] : '?';
                $inp = self::isPlainObj($b['input'] ?? null) ? $b['input'] : [];
                $s['tools']++;
                [$text, $p] = $this->describeTool($name, $inp);
                if ($p !== null && in_array($name, ['Write', 'Edit', 'MultiEdit', 'NotebookEdit'], true)) {
                    $s['files'] = array_values(array_filter($s['files'], static fn($f) => $f !== $p));
                    $s['files'][] = $p;
                    if (count($s['files']) > self::FILES_KEEP) {
                        array_shift($s['files']);
                    }
                }
                if ($name === 'TaskStop' && $t !== '') {
                    $tid = isset($inp['task_id']) && is_string($inp['task_id']) ? $inp['task_id']
                        : (isset($inp['shell_id']) && is_string($inp['shell_id']) ? $inp['shell_id'] : '');
                    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tid)) {
                        $s['stops'][] = [$tid, $t];
                        if (count($s['stops']) > self::STOPS_KEEP) {
                            array_shift($s['stops']);
                        }
                    }
                }
                $this->todo($s, $name, $inp, $t);
                if ($name === 'SubagentHandback') {
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
        }
        $stop = isset($msg['stop_reason']) && is_string($msg['stop_reason']) ? $msg['stop_reason'] : '';
        if ($handback) {
            $s['lastKind'] = 'handback';
        } elseif ($hasTool) {
            $s['lastKind'] = 'tool';
        } elseif ($hasText) {
            $s['lastKind'] = $stop === 'end_turn' ? 'final' : 'text';
        } elseif ($s['lastKind'] === null) {
            $s['lastKind'] = 'thinking';
        }
        if ($hasTool) {
            $s['limit'] = null;
        }
        $n = count($s['segs']);
        if ($stop === 'end_turn' && $t !== '' && $n && $s['segs'][$n - 1][1] === null) {
            $s['segs'][$n - 1][1] = $t;
        }
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $inp */
    private function todo(array &$s, string $name, array $inp, string $t): void
    {
        if ($name === 'TodoWrite' && is_array($inp['todos'] ?? null)) {
            $items = [];
            foreach (self::values($inp['todos']) as $td) {
                if (!is_array($td)) {
                    continue;
                }
                $text = isset($td['content']) && is_string($td['content']) ? $td['content']
                    : (isset($td['subject']) && is_string($td['subject']) ? $td['subject'] : '');
                if (trim($text) === '') {
                    continue;
                }
                $items[] = ['text' => KUtil::safeLine($text, 120), 'status' => self::todoStatus($td['status'] ?? null)];
                if (count($items) >= self::TODOS_KEEP) {
                    break;
                }
            }
            $s['todos'] = $items;
            $s['todosAt'] = $t;
            $s['todoSource'] = 'TodoWrite';
            return;
        }
        if ($name === 'TaskCreate') {
            $subject = isset($inp['subject']) && is_string($inp['subject']) ? $inp['subject']
                : (isset($inp['description']) && is_string($inp['description']) ? $inp['description'] : '');
            $s['taskSeq']++;
            if (trim($subject) === '') {
                return;
            }
            $s['tasks']['#' . $s['taskSeq']] = ['text' => KUtil::safeLine($subject, 120), 'status' => 'pending'];
            $keys = array_keys($s['tasks']);
            if (count($keys) > self::TODOS_KEEP) {
                unset($s['tasks'][$keys[0]]);
            }
        } elseif ($name === 'TaskUpdate') {
            $raw = $inp['taskId'] ?? $inp['id'] ?? null;
            $tid = (is_string($raw) || is_int($raw)) ? ('#' . $raw) : '';
            if (!array_key_exists($tid, $s['tasks'])) {
                return;
            }
            if (($inp['status'] ?? null) === 'deleted') {
                unset($s['tasks'][$tid]);
            } else {
                if (isset($inp['status']) && is_string($inp['status'])) {
                    $s['tasks'][$tid]['status'] = self::todoStatus($inp['status']);
                }
                if (isset($inp['subject']) && is_string($inp['subject']) && trim($inp['subject']) !== '') {
                    $s['tasks'][$tid]['text'] = KUtil::safeLine($inp['subject'], 120);
                }
            }
        } else {
            return;
        }
        $s['todos'] = array_values($s['tasks']);
        $s['todosAt'] = $t;
        $s['todoSource'] = 'Task';
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
        $p = null;
        foreach (['file_path', 'notebook_path'] as $k) {
            if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') {
                $v = $in[$k];
                $p = str_starts_with($v, $this->projectDir . '/') ? substr($v, strlen($this->projectDir) + 1) : self::basename($v);
                break;
            }
        }
        $text = match ($name) {
            'Read' => 'Membaca ' . ($p ?? ''),
            'Write' => 'Menulis ' . ($p ?? ''),
            'Edit', 'MultiEdit', 'NotebookEdit' => 'Mengubah ' . ($p ?? ''),
            // ponytail: argumen Bash tidak pernah ditampilkan — deskripsi saja.
            'Bash' => 'Menjalankan: ' . ($str($in['description'] ?? null) !== '' ? $str($in['description']) : (self::firstToken($str($in['command'] ?? null)) . ' …')),
            'Grep' => "Mencari '" . KUtil::clip($str($in['pattern'] ?? null), 50) . "'",
            'Glob' => 'Mencari file ' . KUtil::clip($str($in['pattern'] ?? null), 60),
            'Agent', 'Task' => 'Mendelegasikan: ' . ($str($in['description'] ?? null) !== '' ? $str($in['description']) : 'subagent'),
            'SendMessage' => 'Mengirim pesan ke agent',
            'AskUserQuestion' => 'Bertanya ke user',
            'WebFetch', 'WebSearch' => 'Riset web',
            'Skill' => 'Memuat skill ' . $str($in['skill'] ?? null),
            'TodoWrite' => 'Memperbarui daftar tugas',
            'TaskCreate' => 'Membuat tugas: ' . $str($in['subject'] ?? null),
            'TaskUpdate' => 'Memperbarui tugas' . ($str($in['status'] ?? null) !== '' ? (' → ' . $str($in['status'])) : ''),
            'TaskStop' => 'Menghentikan subagent',
            'SubagentHandback' => 'Menyerahkan laporan',
            'ToolSearch' => 'Mencari alat',
            default => KUtil::clip($name, 60),
        };
        return [KUtil::oneLine($text, 160), $p];
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
            'v' => self::CACHE_V, 'offset' => 0, 'headLen' => 0, 'head' => '', 'started' => null, 'updated' => null,
            'tools' => 0, 'tokens' => ['in' => 0, 'out' => 0, 'cache' => 0], 'lastMsgId' => null,
            'events' => [], 'lastKind' => null, 'limit' => null, 'files' => [], 'todos' => null, 'todosAt' => null,
            'todoSource' => null, 'tasks' => [], 'taskSeq' => 0, 'segs' => [], 'stops' => [],
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

    private static function isEmpty(mixed $v): bool
    {
        if ($v === null || $v === false || $v === 0 || $v === '' || $v === '0') {
            return true;
        }
        if (is_array($v)) {
            return $v === [];
        }
        return false;
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
