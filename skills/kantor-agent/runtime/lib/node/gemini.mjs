// Sesi Gemini CLI (~/.gemini/tmp/<slug|hash>/chats/) — read-only.
// Hasil tool & prompt user TIDAK PERNAH dibaca. Gaya mengikuti claude.mjs / kiro.mjs / omp.mjs.
//
// LOKASI:
//   <base>/                                    $GEMINI_SESSIONS_DIR, default [$GEMINI_CLI_HOME||$HOME]/.gemini/tmp
//   <base>/<slug>/chats/                       modern: slug = slugify(basename) dari projects.json
//   <base>/<sha256hex>/chats/                  lawas: sha256 hex string path root (getProjectHash upstream)
//   <base>/../projects.json                    registry modern: {projects: {<abs path>: <slug>}}
//   <base>/<slug>/.project_root                marker pemilik (ditulis ProjectRegistry upstream)
//   <base>/<slug>/checkpoints/                  DIABAIKAN — hanya chats yang dibaca
// POLA NAMA BERKAS:
//   chats/session-<YYYY-MM-DD>T<HH-MM>-<shortId>[...].jsonl   sesi utama (Ketua)
//   chats/<parentSessionId>/<sessionId>.jsonl                 subagent (kind='subagent')
//   chats/session-*.json                                      lawas: satu JSON utuh {sessionId,...,messages:[]}
// FIELD YANG DIBACA:
//   metadata {sessionId, kind, startTime, lastUpdated, summary} (+ projectHash/.project_root
//     hanya untuk pencocokan proyek, bukan aktivitas)
//   pesan {id, timestamp, type, content(text saja), toolCalls[]:{id,name,args}, tokens{input,output,cached}}
//   kontrol {$set:{...}} (patch metadata, messages=checkpoint) dan {$rewindTo:id} (potong riwayat)
//   toolCalls[].result, content pesan user, thought TIDAK PERNAH dibaca; type info/error/warning diabaikan.
//   Format tak punya daftar tugas → todos selalu null.
// SUBAGENT: penanda = lokasi nested + kind='subagent'. Bila tak ada penanda → semua = sesi utama.
//
// Pencocokan proyek (jujur, hash modern tak bisa di-invers):
//   1. projects.json (path absolut ternormalisasi → slug), 2. marker .project_root,
//   3. kandidat sha256 lawas (mentah/resolved/realpath), 4. FALLBACK pindai semua slug +
//      cocokkan marker atau metadata projectHash. Algoritma sha256 lawas TERPECahkan
//      (crypto.sha256.update(path).hex ala upstream), tapi string path yang di-hash
//      rapuh (tergantung cwd string saat CLI jalan) → fallback (4) yang diandalkan.
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
  basename, clip, isDir, isFile, isPlainObj, isoMs, mbLen, mtimeSec, phpTrim, readText, redact,
  oneLine, safeLine, strcmp, tsMs, values,
} from './util.mjs';

const CACHE_V = 1;
const HEAD_MAX = 1024; // sidik jari awal berkas: berkas diganti (bukan ditambah) → ringkasan dibuat ulang
const EVENTS_KEEP = 40;
const SEGS_KEEP = 20;
const FILES_KEEP = 12;
const TODOS_KEEP = 40; // ponytail: format tak punya todo → konstanta dokumentasi saja
const utf8 = new TextDecoder('utf-8', { fatal: true });

// ponytail: dir ditemukan sekali per proses; pindah GEMINI_SESSIONS_DIR perlu restart server.
let dirMemo = null;
let regMemo = null;

export function geminiDir() {
  if (dirMemo !== null) return dirMemo;
  const env = process.env.GEMINI_SESSIONS_DIR || '';
  if (env !== '') return (dirMemo = env.replace(/\/+$/, ''));
  const home = (process.env.GEMINI_CLI_HOME || process.env.HOME || os.homedir() || '').replace(/\/+$/, '');
  return (dirMemo = `${home}/.gemini/tmp`);
}

function registryFile() {
  if (regMemo !== null) return regMemo;
  if (process.env.GEMINI_SESSIONS_DIR) return (regMemo = `${path.dirname(geminiDir())}/projects.json`);
  const home = (process.env.GEMINI_CLI_HOME || process.env.HOME || os.homedir() || '').replace(/\/+$/, '');
  return (regMemo = `${home}/.gemini/projects.json`);
}

// ponytail: cermin ProjectRegistry upstream (slugify + normalizePath minimal).
function slugify(text) {
  const s = String(text || '').toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
  return s || 'project';
}
function norm(p) {
  let r = path.resolve(String(p));
  if (process.platform === 'win32') r = r.toLowerCase();
  return r.replace(/\/+$/, '') || r;
}
const sha256hex = (s) => crypto.createHash('sha256').update(s).digest('hex');

function markerOf(slugDir) {
  const t = readText(path.join(slugDir, '.project_root'));
  return t === null ? null : norm(phpTrim(t));
}

function candidateHashes(projectDir) {
  const out = new Set();
  const raws = [String(projectDir)];
  try {
    raws.push(fs.realpathSync(String(projectDir)));
  } catch { /* abaikan */ }
  raws.push(path.resolve(String(projectDir)));
  for (const r of raws) {
    out.add(sha256hex(r));
    out.add(sha256hex(r.replace(/\/+$/, '')));
  }
  return out;
}

// metadata baris pertama .jsonl (tanpa parse penuh) → projectHash sesi
function peekHash(file) {
  let fd = null;
  try {
    fd = fs.openSync(file, 'r');
    const b = Buffer.alloc(4096);
    const got = fs.readSync(fd, b, 0, b.length, 0);
    const line = utf8.decode(b.subarray(0, got)).split('\n')[0] ?? '';
    const row = JSON.parse(line);
    if (isPlainObj(row) && typeof row.projectHash === 'string') return row.projectHash;
    if (isPlainObj(row) && typeof row.sessionId === 'string' && Array.isArray(row.messages)) {
      return typeof row.projectHash === 'string' ? row.projectHash : null;
    }
  } catch { /* abaikan */ } finally {
    if (fd !== null) fs.closeSync(fd);
  }
  return null;
}

function projectRoots(projectDir) {
  const base = geminiDir();
  if (!isDir(base)) return [];
  const want = norm(projectDir);
  const found = [];
  const add = (chats) => { if (isDir(chats) && !found.includes(chats)) found.push(chats); };
  // 1. registry modern projects.json
  try {
    const reg = JSON.parse(readText(registryFile()) ?? '');
    const slug = isPlainObj(reg) && isPlainObj(reg.projects) ? reg.projects[want] : null;
    if (typeof slug === 'string' && /^[a-z0-9-]+$/.test(slug)) add(path.join(base, slug, 'chats'));
  } catch { /* registry opsional */ }
  let names;
  try {
    names = fs.readdirSync(base).filter((x) => !x.startsWith('.')).sort(strcmp);
  } catch {
    return found;
  }
  // 2. marker .project_root yang cocok
  for (const n of names) {
    const d = path.join(base, n);
    try {
      if (!fs.statSync(d).isDirectory()) continue;
    } catch { continue; }
    if (markerOf(d) === want) add(path.join(d, 'chats'));
  }
  if (found.length) return found;
  // 3. kandidat sha256 lawas
  const hashes = candidateHashes(projectDir);
  for (const n of names) {
    if (!/^[a-f0-9]{64}$/.test(n) || !hashes.has(n)) continue;
    const d = path.join(base, n);
    const m = markerOf(d);
    if (m === null || m === want) add(path.join(d, 'chats'));
  }
  if (found.length) return found;
  // 4. FALLBACK: pindai semua slug, cocokkan metadata projectHash
  const slug = slugify(basename(want));
  for (const n of names) {
    if (/^[a-f0-9]{64}$/.test(n)) continue;
    if (n !== slug && !n.startsWith(`${slug}-`)) continue;
    const chats = path.join(base, n, 'chats');
    if (!isDir(chats)) continue;
    let files;
    try {
      files = fs.readdirSync(chats).filter((x) => x.endsWith('.jsonl') && !x.startsWith('.')).sort(strcmp);
    } catch { continue; }
    for (const x of files.slice(0, 8)) {
      if (hashes.has(peekHash(path.join(chats, x)))) { add(chats); break; }
    }
  }
  return found;
}

const memo = new Map(); // file → {size, mtimeMs, head, s}: menghindari parse ulang cache di proses Node yang sama

let lastGood = null; // ponytail: file terkunci/rusak → hasil bagus terakhir, bukan kantor kosong.

export class Gemini {
  constructor(projectDir, storageDir, cfg) {
    this.projectDir = projectDir;
    this.cacheDir = storageDir ? path.join(storageDir, 'cache') : null;
    this.cfg = cfg;
  }

  scan(nowSec) {
    const roots = projectRoots(this.projectDir);
    if (!roots.length) return { exists: false, runs: [], mains: [] };
    try {
      return (lastGood = this.scanRoots(roots, nowSec));
    } catch {
      return lastGood ?? { exists: true, runs: [], mains: [] };
    }
  }

  scanRoots(roots, nowSec) {
    const cutoff = nowSec - this.cfg.window_days * 86400;
    let mains = [];
    let runs = [];
    for (const root of roots) {
      const r = this.scanRoot(root, cutoff);
      mains = mains.concat(r.mains);
      runs = runs.concat(r.runs);
    }
    mains.sort((a, b) => (a.updated < b.updated ? 1 : a.updated > b.updated ? -1 : strcmp(a.session, b.session)));
    mains = mains.slice(0, this.cfg.mains_max);
    runs.sort((a, b) => (a.started < b.started ? -1 : a.started > b.started ? 1 : strcmp(a.id, b.id)));
    return { exists: true, runs, mains };
  }

  scanRoot(root, cutoff) {
    let files;
    try {
      files = fs.readdirSync(root)
        .filter((n) => !n.startsWith('.') && (n.endsWith('.jsonl') || n.endsWith('.json')) && n.length > 5)
        .map((n) => path.join(root, n));
    } catch {
      return { mains: [], runs: [] };
    }
    let mainFiles = files.map((f) => [f, mtimeSec(f)]).filter(([, mt]) => mt >= cutoff);
    mainFiles.sort((a, b) => b[1] - a[1] || strcmp(a[0], b[0]));
    mainFiles = mainFiles.slice(0, this.cfg.mains_max);
    // ponytail: format Gemini tak mencatat cwd per sesi → tak ada saring cwd; roots sudah terverifikasi.
    const mains = mainFiles.map(([f]) => {
      const s = this.state(f);
      return {
        ...this.view(s),
        session: s.sid !== null ? s.sid : basename(f).replace(/\.(jsonl|json)$/, ''),
        agentType: 'general-purpose',
        description: s.summary !== '' ? safeLine(s.summary, 140) : '',
      };
    });
    const runs = [];
    let subs;
    try {
      subs = fs.readdirSync(root).filter((n) => !n.startsWith('.')).sort(strcmp);
    } catch {
      subs = [];
    }
    for (const n of subs) {
      const d = path.join(root, n);
      if (!isDir(d)) continue;
      for (const cf of walkSession(d)) {
        if (mtimeSec(cf) < cutoff) continue;
        const s = this.state(cf);
        if (s.started === null) continue; // belum ada pesan bertanggal
        const sid = s.sid !== null ? s.sid : basename(cf).replace(/\.(jsonl|json)$/, '');
        runs.push({
          ...this.view(s),
          id: sid,
          session: n,
          agentType: 'general-purpose',
          description: s.summary !== '' ? safeLine(s.summary, 140) : '',
          parentAgent: n,
        });
      }
    }
    return { mains, runs };
  }

  state(file) {
    let st;
    try {
      st = fs.statSync(file);
    } catch {
      st = { size: 0, mtimeMs: 0 };
    }
    const size = st.size;
    const head = headOf(file, Math.min(size, HEAD_MAX));
    const m = memo.get(file);
    let s = m && m.size === size && m.mtimeMs === st.mtimeMs && m.head === head ? m.s : null;
    const cacheFile = this.cacheDir ? path.join(this.cacheDir, `g-${crypto.createHash('md5').update(file).digest('hex')}.json`) : null;
    if (s === null && cacheFile && isFile(cacheFile)) {
      try {
        const c = JSON.parse(readText(cacheFile) ?? '');
        // ponytail: $rewindTo/$set.messages membuat append-offset tak valid → parse penuh bila berubah.
        if (isPlainObj(c) && c.v === CACHE_V && c.size === size && c.mtimeMs === st.mtimeMs && c.head === head) s = c.s;
      } catch { s = null; }
    }
    if (s === null || !isPlainObj(s)) {
      s = fresh();
      const raw = readText(file);
      if (raw !== null) {
        if (file.endsWith('.json')) {
          try {
            const rec = JSON.parse(raw);
            if (isPlainObj(rec)) {
              if (Array.isArray(rec.messages)) {
                this.consume(s, { sessionId: rec.sessionId, projectHash: rec.projectHash, startTime: rec.startTime, lastUpdated: rec.lastUpdated, kind: rec.kind, summary: rec.summary });
                const rows = foldRows(rec.messages.filter(isPlainObj));
                for (const row of rows) this.consume(s, row);
              }
            }
          } catch { /* berkas rusak: ringkasan kosong */ }
        } else {
          const rows = [];
          for (const line of raw.split('\n')) {
            if (phpTrim(line) === '') continue;
            try {
              const row = JSON.parse(line);
              if (isPlainObj(row)) rows.push(row);
            } catch { /* baris rusak dilewati */ }
          }
          for (const row of foldRows(rows)) this.consume(s, row);
        }
      }
      closeSeg(s);
      if (cacheFile) {
        try {
          fs.mkdirSync(path.dirname(cacheFile), { recursive: true });
          fs.writeFileSync(cacheFile, JSON.stringify({ v: CACHE_V, size, mtimeMs: st.mtimeMs, head, s }));
        } catch { /* cache opsional */ }
      }
    }
    memo.set(file, { size, mtimeMs: st.mtimeMs, head, s });
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
    if ('$rewindTo' in row || '$set' in row) return; // kontrol sudah dilipat di foldRows
    if (typeof row.sessionId === 'string' && typeof row.projectHash === 'string' && !('type' in row)) {
      // ponytail: baris metadata — hanya id/judul/waktu, bukan aktivitas.
      if (s.sid === null && row.sessionId !== '') s.sid = row.sessionId;
      if (typeof row.summary === 'string' && phpTrim(row.summary) !== '') s.summary = row.summary;
      if (s.started === null && typeof row.startTime === 'string' && tsMs(row.startTime) !== null) s.started = row.startTime;
      if (typeof row.lastUpdated === 'string' && tsMs(row.lastUpdated) !== null) s.updated = row.lastUpdated;
      return;
    }
    if (typeof row.id !== 'string' || typeof row.type !== 'string') return; // baris tak dikenal diabaikan
    const t = typeof row.timestamp === 'string' && tsMs(row.timestamp) !== null ? row.timestamp : '';
    if (row.type === 'user') {
      // ponytail: prompt user tidak pernah ditampilkan — hanya penanda.
      if (t !== '') {
        if (s.started === null) s.started = t;
        s.updated = t;
        segGap(s, t, this.cfg.cooldown);
        this.push(s, t, 'user', 'Instruksi dari user', null);
      }
      s.lastKind = 'user';
      return;
    }
    if (row.type !== 'gemini' && row.type !== 'model') return; // info/error/warning: dilewati total.
    if (t === '') return;
    if (s.started === null) s.started = t;
    s.updated = t;
    segGap(s, t, this.cfg.cooldown);
    const tk = isPlainObj(row.tokens) ? row.tokens : {};
    s.tokens.in += int(tk.input);
    s.tokens.out += int(tk.output);
    s.tokens.cache += int(tk.cached) + int(tk.thoughts) + int(tk.tool);
    let hasTool = false;
    let hasText = false;
    for (const tc of Array.isArray(row.toolCalls) ? row.toolCalls : []) {
      if (!isPlainObj(tc)) continue;
      const name = typeof tc.name === 'string' ? tc.name : '?';
      const args = isPlainObj(tc.args) ? tc.args : {};
      // ponytail: HASIL tool (tc.result) TIDAK PERNAH dibaca — hanya nama & argumen path.
      hasTool = true;
      s.tools++;
      const [text, p] = this.describeTool(name, args);
      if (p !== null) {
        s.files = s.files.filter((f) => f !== p);
        s.files.push(p);
        if (s.files.length > FILES_KEEP) s.files.shift();
      }
      this.push(s, t, 'tool', text, name);
    }
    for (const txt of textsOf(row.content)) {
      hasText = true;
      if (/(usage limit|rate limit|limit reached|resets? (at|in))/i.test(txt) && mbLen(txt) < 400) s.limit = clip(redact(txt), 200);
      this.push(s, t, 'text', safeLine(txt, 180), null);
    }
    if (hasTool) { s.lastKind = 'tool'; s.limit = null; }
    else if (hasText) s.lastKind = 'text';
    else if (s.lastKind === null) s.lastKind = 'thinking';
  }

  push(s, t, kind, text, tool) {
    if (t === '') return;
    s.events.push({ t, kind, text, tool });
    if (s.events.length > EVENTS_KEEP) s.events.splice(0, s.events.length - EVENTS_KEEP);
  }

  describeTool(name, args) {
    const str = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isInteger(v) ? String(v) : '');
    const rel = (p) => (p.startsWith(`${this.projectDir}/`) ? p.slice(this.projectDir.length + 1) : basename(p));
    let f = null;
    for (const k of ['file_path', 'path', 'filePath', 'dir_path', 'absolute_path']) {
      if (typeof args[k] === 'string' && args[k] !== '') { f = rel(args[k]); break; }
    }
    const withFile = f !== null && ['read_file', 'read_many_files', 'write_file', 'replace', 'list_directory'].includes(name);
    let text;
    switch (name) {
      case 'read_file': case 'read_many_files': text = `Membaca ${f ?? ''}`; break;
      case 'write_file': text = `Menulis ${f ?? ''}`; break;
      case 'replace': text = `Mengubah ${f ?? ''}`; break;
      case 'list_directory': text = `Melihat direktori ${f ?? ''}`; break;
      // ponytail: argumen shell tidak pernah ditampilkan utuh — purpose saja.
      case 'run_shell_command': case 'shell': text = `Menjalankan: ${str(args.description || args.purpose) !== '' ? str(args.description || args.purpose) : `${firstToken(str(args.command))} …`}`; break;
      case 'grep_search': case 'grep': case 'search': text = `Mencari '${clip(str(args.pattern), 50)}'`; break;
      case 'glob': text = `Mencari file ${clip(str(args.pattern), 60)}`; break;
      case 'web_fetch': case 'web_search': case 'google_web_search': text = 'Riset web'; break;
      case 'save_memory': text = 'Menyimpan memori'; break;
      case 'ask_user': case 'question': text = 'Bertanya ke user'; break;
      case 'todo': case 'todowrite': text = 'Memperbarui daftar tugas'; break;
      case 'task': case 'subagent': text = `Mendelegasikan: ${str(args.description) !== '' ? str(args.description) : 'subagent'}`; break;
      case 'update_topic': text = `Topik: ${clip(str(args.title), 60)}`; break;
      default: text = clip(name, 60); // ponytail: nama tool bervariasi antar versi; tampil apa adanya.
    }
    return [oneLine(text, 160), withFile ? f : null];
  }
}

// lipat kontrol JSONL: $rewindTo memotong pesan, $set.messages mengganti semua, $set lain menambal meta.
function foldRows(rows) {
  let meta = null;
  let msgs = [];
  const byId = new Map();
  for (const row of rows) {
    if ('$rewindTo' in row) {
      const id = row.$rewindTo;
      const i = msgs.findIndex((m) => m.id === id);
      msgs = i >= 0 ? msgs.slice(0, i) : [];
      byId.clear();
      for (const m of msgs) byId.set(m.id, m);
      continue;
    }
    if ('$set' in row && isPlainObj(row.$set)) {
      const set = row.$set;
      if (Array.isArray(set.messages)) {
        msgs = set.messages.filter(isPlainObj);
        byId.clear();
        for (const m of msgs) if (typeof m.id === 'string') byId.set(m.id, m);
      }
      if (meta !== null) meta = { ...meta, ...set };
      else if (typeof set.sessionId === 'string') meta = { ...set };
      continue;
    }
    if (typeof row.sessionId === 'string' && typeof row.projectHash === 'string' && !('type' in row)) {
      meta = meta === null ? { ...row } : { ...meta, ...row };
      continue;
    }
    if (typeof row.id === 'string') {
      if (byId.has(row.id)) msgs[msgs.indexOf(byId.get(row.id))] = row;
      else msgs.push(row);
      byId.set(row.id, row);
    }
  }
  return [...(meta !== null ? [meta] : []), ...msgs];
}

// berkas sesi di bawah subdir (sarang subagent), rekursif, tanpa entri tersembunyi, terurut byte
function walkSession(dir) {
  let out = [];
  let names;
  try {
    names = fs.readdirSync(dir);
  } catch {
    return out;
  }
  for (const n of names.filter((x) => !x.startsWith('.')).sort(strcmp)) {
    const f = path.join(dir, n);
    if ((n.endsWith('.jsonl') || n.endsWith('.json')) && n.length > 5) {
      try {
        if (fs.statSync(f).isFile()) out.push(f);
      } catch { /* dilewati */ }
    } else {
      try {
        if (fs.statSync(f).isDirectory()) out = out.concat(walkSession(f));
      } catch { /* dilewati */ }
    }
  }
  return out;
}

// teks asisten: string langsung atau gabungan part {text}; tanpa itu → [] (thoughts tanpa event)
function textsOf(content) {
  if (typeof content === 'string') {
    const t = phpTrim(content);
    return t === '' ? [] : [t];
  }
  const out = [];
  for (const p of Array.isArray(content) ? content : values(content)) {
    if (isPlainObj(p) && typeof p.text === 'string' && phpTrim(p.text) !== '') out.push(phpTrim(p.text));
  }
  return out;
}

// segmen kerja: dibuka pesan pertama; jeda > cooldown menutup lalu membuka baru; terakhir tetap terbuka.
function segGap(s, t, cooldown) {
  const ms = tsMs(t);
  if (!s.segs.length) {
    s.segs.push([t, null]);
    s.lastMs = ms;
    return;
  }
  if (ms - s.lastMs > cooldown * 1000) {
    s.segs[s.segs.length - 1][1] = isoMs(s.lastMs);
    s.segs.push([t, null]);
    if (s.segs.length > SEGS_KEEP) s.segs.shift();
  }
  s.lastMs = ms;
}
function closeSeg(s) {
  if (s.started !== null && !s.segs.length) s.segs.push([s.started, null]);
  delete s.lastMs; // ponytail: hanya penghitung jeda saat parse, bukan bagian cache abadi
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
    sid: null, summary: '', started: null, updated: null, tools: 0,
    tokens: { in: 0, out: 0, cache: 0 },
    events: [], lastKind: null, limit: null, files: [], todos: null, todosAt: null, todoSource: null,
    segs: [], lastMs: 0,
  };
}
function int(v) {
  return typeof v === 'number' && Number.isFinite(v) ? Math.trunc(v) : 0;
}
function firstToken(cmd) {
  for (const tok of cmd.split(/[ \n]+/)) if (tok !== '') return tok;
  return '';
}
