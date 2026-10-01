// Sesi Oh My Pi / omp (~/.omp/agent/sessions/<encoded-cwd>/*.jsonl) — read-only.
// Isi tool_result TIDAK PERNAH dibaca. Gaya mengikuti claude.mjs / kiro.mjs.
//   <root>/<timestamp>_<id>.jsonl          sesi utama (Ketua)
//   <root>/<timestamp>_<id>/<AgentId>.jsonl (rekursif)  subagent (Tim/Freelancer)
// Baris: {type:'session'|'title'|'title_change'|'message'|'model_usage'|...}; pesan di
// {type:'message', id, timestamp, message:{role, content:[...], usage?}};
// toolCall di konten assistant {type:'toolCall', name, arguments}.
// encoding cwd = upstream omp session-paths.ts: canonical (realpath) lalu
// '-'+relatif-home | '-tmp'+relatif-tmp | '--absolut--', pemisah → '-'.
// BUKAN munge ala claude (seluruh path) — itu tak cocok dengan data nyata.
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
  basename, clip, globDir, isDir, isFile, isPlainObj, mbLen, mtimeSec, phpTrim, readText, redact,
  oneLine, safeLine, strcmp, tsMs, values,
} from './util.mjs';

const CACHE_V = 1;
const HEAD_MAX = 1024; // sidik jari awal berkas: berkas diganti (bukan ditambah) → ringkasan dibuat ulang
const EVENTS_KEEP = 40;
const SEGS_KEEP = 20;
const FILES_KEEP = 12;
const TODOS_KEEP = 40;
const utf8 = new TextDecoder('utf-8', { fatal: true });

// ponytail: dir ditemukan sekali per proses; pindah OMP_SESSIONS_DIR perlu restart server.
let dirMemo = null;

export function ompDir() {
  if (dirMemo !== null) return dirMemo;
  const env = process.env.OMP_SESSIONS_DIR || '';
  if (env !== '') return (dirMemo = env.replace(/\/+$/, ''));
  return (dirMemo = `${(process.env.HOME || os.homedir() || '').replace(/\/+$/, '')}/.omp/agent/sessions`);
}

// ponytail: encoder = oh-my-pi session-paths.ts (bukan munge claude);
// data nyata mesin ini cocok semua: /home/nayaka/camat-trk→-camat-trk, /tmp→-tmp, $HOME→'-'.
function canon(p) {
  try {
    return fs.realpathSync(String(p));
  } catch {
    return path.resolve(String(p));
  }
}
function relName(prefix, rel) {
  const enc = rel.replace(/[/\\:]/g, '-');
  return enc ? (prefix.endsWith('-') ? `${prefix}${enc}` : `${prefix}-${enc}`) : prefix;
}
function encodeBucket(cwd) {
  const c = canon(cwd);
  const relH = path.relative(canon(os.homedir()), c);
  if (relH === '' || (!relH.startsWith('..') && !path.isAbsolute(relH))) return relName('-', relH);
  const relT = path.relative(canon(os.tmpdir()), c);
  if (relT === '' || (!relT.startsWith('..') && !path.isAbsolute(relT))) return relName('-tmp', relT);
  return `--${c.replace(/^[/\\]/, '').replace(/[/\\:]/g, '-')}--`;
}

// judul/header tanpa parse penuh: 4 KiB pertama cukup (slot title + header di awal)
function headerCwd(file) {
  let fd = null;
  try {
    fd = fs.openSync(file, 'r');
    const b = Buffer.alloc(4096);
    const got = fs.readSync(fd, b, 0, b.length, 0);
    for (const line of utf8.decode(b.subarray(0, got)).split('\n')) {
      if (phpTrim(line) === '') continue;
      let row = null;
      try {
        row = JSON.parse(line);
      } catch {
        continue;
      }
      if (isPlainObj(row) && row.type === 'session') return typeof row.cwd === 'string' ? row.cwd : null;
      if (isPlainObj(row) && row.type === 'message') break; // header tak ketemu sebelum pesan
    }
  } catch {
    /* abaikan */
  } finally {
    if (fd !== null) fs.closeSync(fd);
  }
  return null;
}

function projectRoot(projectDir) {
  const base = ompDir();
  const exact = `${base}/${encodeBucket(projectDir)}`;
  if (isDir(exact)) return exact;
  // ponytail: bucket warisan (ejaan lama) atau cwd pindah: cocokkan header cwd, bukan nama.
  const want = [String(projectDir).replace(/\/+$/, ''), canon(projectDir)];
  let names;
  try {
    names = fs.readdirSync(base);
  } catch {
    return null;
  }
  for (const n of names.filter((x) => !x.startsWith('.')).sort(strcmp)) {
    const d = `${base}/${n}`;
    let st;
    try {
      st = fs.statSync(d);
    } catch {
      continue;
    }
    if (!st.isDirectory()) continue;
    let files;
    try {
      files = fs.readdirSync(d).filter((x) => x.endsWith('.jsonl') && !x.startsWith('.')).sort(strcmp);
    } catch {
      continue;
    }
    for (const x of files.slice(0, 8)) {
      const cwd = headerCwd(path.join(d, x));
      if (cwd !== null && want.includes(cwd.replace(/\/+$/, ''))) return d;
    }
  }
  return null;
}

const memo = new Map(); // file → {size, mtimeMs, s}: menghindari parse ulang cache di proses Node yang sama

let lastGood = null; // ponytail: file terkunci/rusak → hasil bagus terakhir, bukan kantor kosong.

export class Omp {
  constructor(projectDir, storageDir, cfg) {
    this.projectDir = projectDir;
    this.cacheDir = storageDir ? path.join(storageDir, 'cache') : null;
    this.cfg = cfg;
  }

  scan(nowSec) {
    const root = projectRoot(this.projectDir);
    if (root === null) return { exists: false, runs: [], mains: [] };
    try {
      return (lastGood = this.scanRoot(root, nowSec));
    } catch {
      return lastGood ?? { exists: true, runs: [], mains: [] };
    }
  }

  scanRoot(root, nowSec) {
    const cutoff = nowSec - this.cfg.window_days * 86400;
    const want = [String(this.projectDir).replace(/\/+$/, ''), canon(this.projectDir)];
    let mainFiles = globDir(root, '.jsonl').map((f) => [f, mtimeSec(f)]).filter(([, mt]) => mt >= cutoff);
    mainFiles.sort((a, b) => b[1] - a[1] || strcmp(a[0], b[0]));
    // ponytail: bucket bisa campuran (migrasi); saring header cwd yang tak cocok.
    mainFiles = mainFiles.filter(([f]) => {
      const c = this.state(f).cwd;
      return c === null || want.includes(c.replace(/\/+$/, ''));
    });
    mainFiles = mainFiles.slice(0, this.cfg.mains_max);
    const mains = mainFiles.map(([f]) => {
      const s = this.state(f);
      return {
        ...this.view(s),
        session: s.sid !== null ? s.sid : idFromName(f),
        provider: 'omp',
        agentType: 'general-purpose',
        description: s.title !== '' ? safeLine(s.title, 140) : '',
      };
    });

    const runs = [];
    for (const [f] of mainFiles) {
      const parentSid = this.state(f).sid ?? idFromName(f);
      const sub = f.slice(0, -'.jsonl'.length);
      if (!isDir(sub)) continue;
      for (const cf of walkJsonl(sub)) {
        if (mtimeSec(cf) < cutoff) continue;
        const s = this.state(cf);
        if (s.started === null) continue; // belum ada pesan bertanggal
        const agent = basename(cf, '.jsonl');
        runs.push({
          ...this.view(s),
          id: s.sid !== null ? s.sid : agent,
          session: parentSid,
          provider: 'omp',
          agentType: clip(agent, 40) !== '' ? clip(agent, 40) : 'general-purpose',
          description: s.title !== '' ? safeLine(s.title, 140) : '',
          parentAgent: parentSid,
        });
      }
    }
    runs.sort((a, b) => (a.started < b.started ? -1 : a.started > b.started ? 1 : strcmp(a.id, b.id)));
    return { exists: true, runs, mains };
  }

  state(file) {
    let st;
    try {
      st = fs.statSync(file);
    } catch {
      st = { size: 0, mtimeMs: 0 };
    }
    const size = st.size;
    const m = memo.get(file);
    let s = m && m.size === size && m.mtimeMs === st.mtimeMs && m.head === headOf(file, m.s.headLen) ? m.s : null;
    const cacheFile = this.cacheDir ? path.join(this.cacheDir, `p-${crypto.createHash('md5').update(file).digest('hex')}.json`) : null;
    if (s === null && cacheFile && isFile(cacheFile)) {
      try {
        s = JSON.parse(readText(cacheFile) ?? '');
      } catch {
        s = null;
      }
    }
    if (!isPlainObj(s) || s.v !== CACHE_V || !(s.offset >= 0) || s.offset > size || !(s.headLen >= 0) || s.headLen > size
      || headOf(file, s.headLen) !== s.head) s = fresh();
    if (s.offset < size) {
      let fd = null;
      try {
        fd = fs.openSync(file, 'r');
        let pos = s.offset;
        let carry = Buffer.alloc(0);
        const buf = Buffer.alloc(1 << 20);
        let read;
        let readAt = pos;
        while ((read = fs.readSync(fd, buf, 0, buf.length, readAt)) > 0) {
          readAt += read;
          const chunk = carry.length ? Buffer.concat([carry, buf.subarray(0, read)]) : Buffer.from(buf.subarray(0, read));
          let start = 0;
          let nl;
          while ((nl = chunk.indexOf(10, start)) !== -1) {
            const lineBuf = chunk.subarray(start, nl + 1);
            pos += lineBuf.length;
            start = nl + 1;
            let row = null;
            try {
              row = JSON.parse(utf8.decode(lineBuf));
            } catch {
              row = null;
            }
            if (isPlainObj(row)) this.consume(s, row);
          }
          carry = chunk.subarray(start);
        }
        s.offset = pos; // baris terakhir tanpa \n belum lengkap — dibaca lagi nanti
        s.headLen = Math.min(size, HEAD_MAX);
        s.head = headOf(file, s.headLen);
      } catch {
        /* file hilang/terkunci: pakai ringkasan yang ada */
      } finally {
        if (fd !== null) fs.closeSync(fd);
      }
      if (cacheFile) {
        try {
          fs.mkdirSync(path.dirname(cacheFile), { recursive: true });
          fs.writeFileSync(cacheFile, JSON.stringify(s));
        } catch {
          /* cache opsional */
        }
      }
    }
    memo.set(file, { size, mtimeMs: st.mtimeMs, s, head: s.head });
    return s;
  }

  summarize(file) {
    return this.view(this.state(file));
  }

  view(s) {
    return {
      started: s.started, updated: s.updated, tools: s.tools, tokens: s.tokens.in + s.tokens.out + s.tokens.cache,
      events: s.events, lastKind: s.lastKind, limit: s.limit, files: s.files, todos: s.todos, todosAt: s.todosAt,
      todoSource: s.todoSource, segs: s.segs, stops: [],
    };
  }

  consume(s, row) {
    const type = row.type ?? '';
    if (type === 'session') {
      // ponytail: baris header — hanya id/cwd (+ judul bila ada), bukan aktivitas.
      if (s.sid === null && typeof row.id === 'string' && row.id !== '') s.sid = row.id;
      if (s.cwd === null && typeof row.cwd === 'string' && row.cwd !== '') s.cwd = row.cwd;
      if (typeof row.title === 'string' && phpTrim(row.title) !== '') s.title = row.title;
      return;
    }
    if ((type === 'title' || type === 'title_change') && typeof row.title === 'string') {
      // ponytail: judul awal sering kosong; yang terakhir tak-kosong menang.
      if (phpTrim(row.title) !== '') s.title = row.title;
      return;
    }
    if (type === 'model_usage') {
      // ponytail: akuntansi model di luar transkrip — hanya angka, tanpa event.
      const u = isPlainObj(row.usage) ? row.usage : {};
      s.tokens.in += int(u.input);
      s.tokens.out += int(u.output);
      s.tokens.cache += int(u.cacheRead) + int(u.cacheWrite);
      return;
    }
    if (type !== 'message') return; // model_change/mode_change/custom/dll: dilewati total.
    const t = typeof row.timestamp === 'string' && tsMs(row.timestamp) !== null ? row.timestamp : '';
    const msg = row.message;
    if (!isPlainObj(msg)) return;
    const role = msg.role ?? '';
    if (t !== '') {
      if (s.started === null) s.started = t;
      s.updated = t;
    }
    if (role === 'toolResult' || role === 'developer') {
      // ponytail: HASIL tool & pengingat sistem TIDAK PERNAH dibaca.
      if (s.lastKind !== 'handback' && s.lastKind !== 'final') s.lastKind = 'tool';
      return;
    }
    if (role !== 'user' && role !== 'assistant') return; // bashExecution/fileMention/dll: isi tak dibaca.
    if (t !== '') {
      if (!s.segs.length) s.segs.push([t, null]);
      s.updated = t;
      if (role === 'user' || role === 'assistant') {
        const last = s.segs[s.segs.length - 1];
        if (last && last[1] !== null) {
          if (tsMs(t) - tsMs(last[1]) <= this.cfg.cooldown * 1000) last[1] = null;
          else {
            s.segs.push([t, null]);
            if (s.segs.length > SEGS_KEEP) s.segs.shift();
          }
        }
      }
    }
    if (role === 'user') {
      // ponytail: prompt user tidak pernah ditampilkan — hanya penanda.
      this.push(s, t, 'user', 'Instruksi dari user', null);
      s.lastKind = 'user';
      return;
    }
    const id = typeof row.id === 'string' ? row.id : '';
    const usage = isPlainObj(msg.usage) ? msg.usage : {};
    if (id !== '' && id !== s.lastMsgId) {
      s.tokens.in += int(usage.input);
      s.tokens.out += int(usage.output);
      s.tokens.cache += int(usage.cacheRead) + int(usage.cacheWrite);
      s.lastMsgId = id;
    }
    let hasTool = false;
    let hasText = false;
    let handback = false;
    for (const b of Array.isArray(msg.content) ? msg.content : values(msg.content)) {
      if (!isPlainObj(b)) continue;
      const bt = b.type ?? '';
      if (bt === 'toolCall') {
        hasTool = true;
        const name = typeof b.name === 'string' ? b.name : '?';
        const inp = isPlainObj(b.arguments) ? b.arguments : {};
        s.tools++;
        const [text, p] = this.describeTool(name, inp);
        if (p !== null && name === 'read') {
          s.files = s.files.filter((f) => f !== p);
          s.files.push(p);
          if (s.files.length > FILES_KEEP) s.files.shift();
        }
        this.todo(s, name, inp, t);
        if (name === 'yield') handback = true;
        this.push(s, t, 'tool', text, name);
      } else if (bt === 'text') {
        const txt = typeof b.text === 'string' ? phpTrim(b.text) : '';
        if (txt === '') continue;
        hasText = true;
        if (/(usage limit|rate limit|limit reached|resets? (at|in))/i.test(txt) && mbLen(txt) < 400) s.limit = clip(redact(txt), 200);
        this.push(s, t, 'text', safeLine(txt, 180), null);
      }
      // thinking: tanpa event.
    }
    const stop = typeof msg.stopReason === 'string' ? msg.stopReason : '';
    if (handback) s.lastKind = 'handback';
    else if (hasTool) s.lastKind = 'tool';
    else if (hasText) s.lastKind = stop === 'stop' ? 'final' : 'text';
    else if (s.lastKind === null) s.lastKind = 'thinking';
    if (hasTool) s.limit = null;
    if (stop === 'stop' && t !== '' && s.segs.length && s.segs[s.segs.length - 1][1] === null) s.segs[s.segs.length - 1][1] = t;
  }

  todo(s, name, inp, t) {
    if (name !== 'todo') return;
    let items = inp.items;
    if (typeof items === 'string') {
      try {
        items = JSON.parse(items);
      } catch {
        return;
      }
    }
    if (!Array.isArray(items) || !items.length) return;
    const out = [];
    for (const td of items) {
      if (!isPlainObj(td)) continue;
      const text = typeof td.content === 'string' ? td.content : typeof td.task_description === 'string' ? td.task_description
        : typeof td.subject === 'string' ? td.subject : '';
      if (phpTrim(text) === '') continue;
      out.push({ text: safeLine(text, 120), status: todoStatus(td.status ?? td.task_status) });
      if (out.length >= TODOS_KEEP) break;
    }
    if (!out.length) return;
    s.todos = out;
    s.todosAt = t;
    s.todoSource = 'TodoWrite';
  }

  push(s, t, kind, text, tool) {
    if (t === '') return;
    s.events.push({ t, kind, text, tool });
    if (s.events.length > EVENTS_KEEP) s.events.splice(0, s.events.length - EVENTS_KEEP);
  }

  describeTool(name, inp) {
    const str = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isInteger(v) ? String(v) : '');
    const rel = (p) => (p.startsWith(`${this.projectDir}/`) ? p.slice(this.projectDir.length + 1) : basename(p));
    const p = name === 'read' && typeof inp.path === 'string' && inp.path !== '' ? rel(inp.path) : null;
    let text;
    switch (name) {
      case 'read': text = `Membaca ${typeof inp.path === 'string' ? rel(inp.path) : ''}`; break;
      // ponytail: argumen shell tidak pernah ditampilkan — intent saja.
      case 'bash': text = `Menjalankan: ${str(inp.i) !== '' ? str(inp.i) : `${firstToken(str(inp.command))} …`}`; break;
      case 'grep': text = `Mencari '${clip(str(inp.pattern), 50)}'`; break;
      case 'glob': text = `Mencari file ${clip(str(inp.path ?? inp.pattern), 60)}`; break;
      case 'task': text = `Mendelegasikan: ${str(inp.i) !== '' ? str(inp.i) : 'subagent'}`; break;
      case 'hub': text = 'Mengelola subagent'; break;
      case 'ask': text = 'Bertanya ke user'; break;
      case 'todo': text = 'Memperbarui daftar tugas'; break;
      case 'yield': text = 'Menyerahkan laporan'; break;
      default: text = clip(name, 60);
    }
    return [oneLine(text, 160), p];
  }
}

// berkas anak di bawah subdir sesi, rekursif, tanpa entri tersembunyi, terurut byte
function walkJsonl(dir) {
  let out = [];
  let names;
  try {
    names = fs.readdirSync(dir);
  } catch {
    return out;
  }
  for (const n of names.filter((x) => !x.startsWith('.')).sort(strcmp)) {
    const f = path.join(dir, n);
    if (n.endsWith('.jsonl') && n.length > '.jsonl'.length) {
      try {
        if (fs.statSync(f).isFile()) out.push(f);
      } catch { /* dilewati */ }
    } else {
      try {
        if (fs.statSync(f).isDirectory()) out = out.concat(walkJsonl(f));
      } catch { /* dilewati */ }
    }
  }
  return out;
}

// nama berkas sesi `<timestamp>_<id>.jsonl` → id (sesudah '_' terakhir); darurat: basename penuh
function idFromName(f) {
  const b = basename(f, '.jsonl');
  const i = b.lastIndexOf('_');
  return i >= 0 && i < b.length - 1 ? b.slice(i + 1) : b;
}

// md5 dari n byte pertama berkas ('' bila n = 0 atau gagal dibaca)
function headOf(file, n) {
  if (!(n > 0)) return '';
  let fd = null;
  try {
    fd = fs.openSync(file, 'r');
    const b = Buffer.alloc(n);
    const got = fs.readSync(fd, b, 0, n, 0);
    return got === n ? crypto.createHash('md5').update(b).digest('hex') : '';
  } catch {
    return '';
  } finally {
    if (fd !== null) fs.closeSync(fd);
  }
}
function fresh() {
  return {
    v: CACHE_V, offset: 0, headLen: 0, head: '', sid: null, cwd: null, title: '', started: null, updated: null, tools: 0,
    tokens: { in: 0, out: 0, cache: 0 }, lastMsgId: null,
    events: [], lastKind: null, limit: null, files: [], todos: null, todosAt: null, todoSource: null,
    segs: [],
  };
}
function todoStatus(v) {
  return ['pending', 'in_progress', 'completed'].includes(v) ? v : 'pending';
}
function int(v) {
  return typeof v === 'number' && Number.isFinite(v) ? Math.trunc(v) : 0;
}
function firstToken(cmd) {
  for (const tok of cmd.split(/[ \n]+/)) if (tok !== '') return tok;
  return '';
}
