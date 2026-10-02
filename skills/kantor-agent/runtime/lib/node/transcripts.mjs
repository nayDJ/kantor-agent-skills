// Ringkasan sesi OpenCode dari SQLite (~/.local/share/opencode/opencode.db) — read-only.
// Hanya metadata tool & potongan teks assistant. Output tool (tool_result) TIDAK PERNAH dibaca.
// Port PHP: lib/php/Transcripts.php (harus identik — dicek bin/parity.mjs).
//   session.parent_id IS NULL → sesi utama (Ketua); IS NOT NULL → subagent (Tim/Freelancer).
import crypto from 'node:crypto';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import {
  basename, clip, isFile, isPlainObj, isoMs, mbLen, phpTrim, readText, redact,
  oneLine, safeLine, strcmp,
} from './util.mjs';
import { Kiro } from './kiro.mjs';
import { Claude } from './claude.mjs';
import { Omp } from './omp.mjs';
import { Gemini } from './gemini.mjs';

const CACHE_V = 4;
const EVENTS_KEEP = 40;
const SEGS_KEEP = 20;
const FILES_KEEP = 12;
const TODOS_KEEP = 40;

// ponytail: DB ditemukan sekali per proses; bila user memindah OPENCODE_DB perlu restart server.
let dbPathMemo = null;

export function opencodeDb() {
  if (dbPathMemo !== null) return dbPathMemo;
  const env = process.env.OPENCODE_DB || '';
  if (env !== '') return (dbPathMemo = env);
  const data = process.env.XDG_DATA_HOME || '';
  const base = data !== '' ? data : `${(process.env.HOME || os.homedir() || '').replace(/\/+$/, '')}/.local/share`;
  return (dbPathMemo = `${base.replace(/\/+$/, '')}/opencode/opencode.db`);
}

const require = createRequire(import.meta.url);

function openDb(ro = true) {
  // ponytail: node:sqlite (Node ≥ 22) tanpa dep baru; fallback python3 bila gagal.
  try {
    const { DatabaseSync } = require('node:sqlite');
    return { kind: 'native', db: new DatabaseSync(opencodeDb(), { readOnly: ro }) };
  } catch {
    return { kind: 'py' };
  }
}

function pyQuery(sql, params) {
  // ponytail: python3 ada di setiap env opencode; query kecil, tanpa ORM.
  const { execFileSync } = require('node:child_process');
  const script = 'import sqlite3,json,sys;db=sqlite3.connect("file:"+sys.argv[1]+"?mode=ro",uri=True);db.row_factory=sqlite3.Row;print(json.dumps([dict(r) for r in db.execute(sys.argv[2],json.loads(sys.argv[3]))]))';
  const out = execFileSync('python3', ['-c', script, opencodeDb(), sql, JSON.stringify(params)], { timeout: 10000 });
  return JSON.parse(String(out));
}

function query(handle, sql, params) {
  if (handle.kind === 'native') return handle.db.prepare(sql).all(...params);
  return pyQuery(sql, params);
}

let lastGood = null; // ponytail: DB terkunci/sibuk → sajikan hasil bagus terakhir, bukan kantor kosong.

export class Transcripts {
  constructor(projectDir, storageDir, cfg) {
    this.projectDir = projectDir;
    this.storageDir = storageDir;
    this.cacheDir = storageDir ? path.join(storageDir, 'cache') : null;
    this.cfg = cfg;
  }

  scan(nowSec) {
    // ponytail: lima sumber (OpenCode DB + Kiro CLI + Claude Code + OMP + Gemini) digabung; office yang memilih Ketua & membagi tim.
    let oc = { exists: false, runs: [], mains: [] };    if (isFile(opencodeDb())) {
      try {
        oc = lastGood = this.scanDb(nowSec);
      } catch {
        oc = lastGood ?? { exists: true, runs: [], mains: [] };
      }
    }
    const k = new Kiro(this.projectDir, this.storageDir, this.cfg).scan(nowSec);
    const c = new Claude(this.projectDir, this.storageDir, this.cfg).scan(nowSec);
    const o = new Omp(this.projectDir, this.storageDir, this.cfg).scan(nowSec);
    const g = new Gemini(this.projectDir, this.storageDir, this.cfg).scan(nowSec);
    return { exists: oc.exists || k.exists || c.exists || o.exists || g.exists, runs: [...oc.runs, ...k.runs, ...c.runs, ...o.runs, ...g.runs], mains: [...oc.mains, ...k.mains, ...c.mains, ...o.mains, ...g.mains] };
  }

  scanDb(nowSec) {
    const handle = openDb();
    try {
      const cutoff = (nowSec - this.cfg.window_days * 86400) * 1000;
      const sel = `SELECT id,parent_id,directory,title,agent,time_created,time_updated FROM session`;
      let rows = null;
      try {
        rows = query(handle,
          `${sel}
           WHERE project_id IN (SELECT project_id FROM project_directory WHERE directory=?) AND time_updated>=? AND time_archived IS NULL ORDER BY time_updated DESC`,
          [this.projectDir, cutoff]);
      } catch { rows = null; } // ponytail: DB lama tanpa tabel project_directory → fallback exact-match.
      if (rows === null) {
        rows = query(handle,
          `${sel}
           WHERE directory=? AND time_updated>=? AND time_archived IS NULL ORDER BY time_updated DESC`,
          [this.projectDir, cutoff]);
      } else if (rows.length === 0) {
        // ponytail: COUNT ringan saja bila kosong; project lama tanpa baris project_directory → fallback.
        let n = 0;
        try {
          const c = query(handle, `SELECT COUNT(*) AS n FROM session WHERE directory=? AND time_updated>=? AND time_archived IS NULL`,
            [this.projectDir, cutoff]);
          n = Number(c?.[0]?.n) || 0;
        } catch { n = 0; }
        if (n > 0) rows = query(handle,
          `${sel}
           WHERE directory=? AND time_updated>=? AND time_archived IS NULL ORDER BY time_updated DESC`,
          [this.projectDir, cutoff]);
      }
      const mains = [];
      const kids = [];
      for (const r of rows) {
        if (mains.length + kids.length >= this.cfg.mains_max * 2) break;
        const sum = this.summarize(handle, r);
        const item = {
          ...sum,
          session: r.parent_id ?? r.id,
          provider: 'opencode',
          agentType: typeof r.agent === 'string' && phpTrim(r.agent) !== '' ? clip(phpTrim(r.agent), 40) : 'general-purpose',
          // ponytail: mode = pesan terakhir (assistant.mode; user→agent; compaction→mundur ≤5); plan/build saja.
          mode: sum.mode ?? null,
          description: typeof r.title === 'string' ? safeLine(r.title, 140) : '',
        };
        if (r.parent_id === null) {
          if (mains.length < this.cfg.mains_max) mains.push(item);
        } else {
          // ponytail: parent juga run (subagent bersarang) → office tahu pemintanya; kalau sesi utama → Ketua.
          item.id = String(r.id);
          item.parentAgent = r.parent_id;
          kids.push(item);
        }
      }
      kids.sort((a, b) => (a.started < b.started ? -1 : a.started > b.started ? 1 : strcmp(a.id, b.id)));
      return { exists: true, runs: kids.filter((r) => r.started !== null), mains };
    } finally {
      if (handle.kind === 'native') handle.db.close();
    }
  }

  summarize(handle, sess) {
    const key = `o-${crypto.createHash('md5').update(String(sess.id)).digest('hex')}.json`;
    const cacheFile = this.cacheDir ? path.join(this.cacheDir, key) : null;
    let s = null;
    if (cacheFile && isFile(cacheFile)) {
      try {
        const c = JSON.parse(readText(cacheFile) ?? '');
        // ponytail: sesi tidak berubah (time_updated sama) → pakai cache, tanpa baca DB lagi.
        if (isPlainObj(c) && c.v === CACHE_V && c.updated === sess.time_updated) s = c.s;
      } catch { s = null; }
    }
    if (s === null) {
      s = fresh();
      for (const row of query(handle,
        `SELECT p.data AS pdata,p.time_created AS pt,m.id AS mid,m.data AS mdata FROM part p
         JOIN message m ON m.id=p.message_id WHERE p.session_id=? ORDER BY p.time_created,p.id`,
        [String(sess.id)])) {
        let p, m;
        try { p = JSON.parse(row.pdata); } catch { continue; }
        try { m = JSON.parse(row.mdata); } catch { m = {}; }
        if (isPlainObj(p)) this.consume(s, p, isPlainObj(m) ? m : {}, String(row.mid), Number(row.pt));
      }
      // ponytail: tabel todo sebagai fallback bila tidak ada part todowrite yang lebih baru.
      try {
        const todos = query(handle, `SELECT content,status,time_updated FROM todo WHERE session_id=? ORDER BY position`, [String(sess.id)]);
        const tMax = todos.reduce((a, t) => Math.max(a, Number(t.time_updated) || 0), 0);
        if (todos.length && (s.todosAt === null || tMax >= s.todosAtMs)) {
          s.todos = todos.slice(0, TODOS_KEEP).filter((t) => phpTrim(String(t.content ?? '')) !== '')
            .map((t) => ({ text: safeLine(String(t.content), 120), status: todoStatus(t.status) }));
          s.todosAt = isoMs(tMax);
          s.todoSource = 'Task';
        }
      } catch { /* tabel todo opsional */ }
      s.mode = modeOf(handle, String(sess.id));
      if (cacheFile) {
        try {
          fs.mkdirSync(path.dirname(cacheFile), { recursive: true });
          fs.writeFileSync(cacheFile, JSON.stringify({ v: CACHE_V, updated: sess.time_updated, s }));
        } catch { /* cache opsional */ }
      }
    }
    return {
      started: s.started, updated: s.updated, tools: s.tools, tokens: s.tokens.in + s.tokens.out + s.tokens.cache,
      events: s.events, lastKind: s.lastKind, limit: s.limit, files: s.files, todos: s.todos, todosAt: s.todosAt,
      todoSource: s.todoSource, segs: s.segs, stops: [], mode: s.mode ?? null,
    };
  }

  consume(s, p, m, mid, ms) {
    if (!Number.isFinite(ms)) return;
    const t = isoMs(ms);
    if (s.started === null) s.started = t;
    s.updated = t;
    if (!s.segs.length) s.segs.push([t, null]);
    // token dihitung sekali per pesan (step-finish menduplikasi angka yang sama).
    if (mid !== '' && mid !== s.lastMsgId && isPlainObj(m.tokens)) {
      const u = m.tokens;
      s.tokens.in += int(u.input);
      s.tokens.out += int(u.output) + int(u.reasoning);
      s.tokens.cache += int(u.cache?.read) + int(u.cache?.write);
      s.lastMsgId = mid;
    }
    const role = m.role ?? '';
    const type = p.type ?? '';
    if (type === 'text') {
      const txt = typeof p.text === 'string' ? phpTrim(p.text) : '';
      if (txt === '') return;
      if (role === 'user') {
        // ponytail: prompt user tidak pernah ditampilkan — hanya penanda (warisan perilaku Claude).
        this.push(s, t, 'user', 'Instruksi dari user', null);
        s.lastKind = 'user';
      } else {
        if (/(usage limit|rate limit|limit reached|resets? (at|in))/i.test(txt) && mbLen(txt) < 400) s.limit = clip(redact(txt), 200);
        this.push(s, t, 'text', safeLine(txt, 180), null);
        s.lastKind = 'text';
      }
      return;
    }
    if (type === 'tool') {
      const name = typeof p.tool === 'string' ? p.tool : '?';
      const inp = isPlainObj(p.state?.input) ? p.state.input : {};
      s.tools++;
      const [text, f] = this.describeTool(name, inp);
      if (f !== null && ['write', 'edit'].includes(name)) {
        s.files = s.files.filter((x) => x !== f);
        s.files.push(f);
        if (s.files.length > FILES_KEEP) s.files.shift();
      }
      if (name === 'todowrite' && Array.isArray(inp.todos)) {
        s.todos = inp.todos.filter((td) => isPlainObj(td) && phpTrim(String(td.content ?? '')) !== '')
          .slice(0, TODOS_KEEP).map((td) => ({ text: safeLine(String(td.content), 120), status: todoStatus(td.status) }));
        s.todosAt = t;
        s.todosAtMs = ms;
        s.todoSource = 'TodoWrite';
      }
      this.push(s, t, 'tool', text, name);
      s.lastKind = 'tool';
      s.limit = null;
      return;
    }
    if (type === 'step-start') {
      // ponytail: tiap tool = satu step; seg digabung bila jeda ≤ cooldown supaya satu sesi = satu job.
      const last = s.segs[s.segs.length - 1];
      if (last[1] !== null) {
        if (ms - Date.parse(last[1]) <= this.cfg.cooldown * 1000) last[1] = null;
        else {
          s.segs.push([t, null]);
          if (s.segs.length > SEGS_KEEP) s.segs.shift();
        }
      }
      return;
    }
    if (type === 'step-finish') {
      const last = s.segs[s.segs.length - 1];
      if (last[1] === null) last[1] = t;
      if (p.reason === 'stop' && s.lastKind !== 'user') s.lastKind = 'final';
      return;
    }
    if (type === 'patch' && Array.isArray(p.files)) {
      // ponytail: patch hanya menyumbang daftar file, tanpa event.
      for (const f of p.files) {
        const rel = typeof f === 'string' && f.startsWith(`${this.projectDir}/`) ? f.slice(this.projectDir.length + 1) : basename(String(f ?? ''));
        if (rel === '') continue;
        s.files = s.files.filter((x) => x !== rel);
        s.files.push(rel);
        if (s.files.length > FILES_KEEP) s.files.shift();
      }
    }
    // reasoning/compaction: aktivitas berpikir — seg sudah dibuka di atas, tanpa event.
  }

  push(s, t, kind, text, tool) {
    s.events.push({ t, kind, text, tool });
    if (s.events.length > EVENTS_KEEP) s.events.splice(0, s.events.length - EVENTS_KEEP);
  }

  describeTool(name, inp) {
    const str = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isInteger(v) ? String(v) : '');
    let f = null;
    if (typeof inp.filePath === 'string' && inp.filePath !== '') {
      const v = inp.filePath;
      f = v.startsWith(`${this.projectDir}/`) ? v.slice(this.projectDir.length + 1) : basename(v);
    }
    let text;
    switch (name) {
      case 'read': text = `Membaca ${f ?? ''}`; break;
      case 'write': text = `Menulis ${f ?? ''}`; break;
      case 'edit': text = `Mengubah ${f ?? ''}`; break;
      // ponytail: argumen Bash tidak pernah ditampilkan — deskripsi saja (warisan perilaku Claude).
      case 'bash': text = `Menjalankan: ${str(inp.description) !== '' ? str(inp.description) : `${firstToken(str(inp.command))} …`}`; break;
      case 'grep': text = `Mencari '${clip(str(inp.pattern), 50)}'`; break;
      case 'glob': text = `Mencari file ${clip(str(inp.pattern), 60)}`; break;
      case 'task': text = `Mendelegasikan: ${str(inp.description) !== '' ? str(inp.description) : 'subagent'}`; break;
      case 'question': text = 'Bertanya ke user'; break;
      case 'webfetch': case 'websearch': text = 'Riset web'; break;
      case 'todowrite': text = 'Memperbarui daftar tugas'; break;
      case 'skill': text = `Memuat skill ${str(inp.skill)}`; break;
      default: text = clip(name, 60);
    }
    return [oneLine(text, 160), f];
  }
}

function fresh() {
  return {
    started: null, updated: null, tools: 0, tokens: { in: 0, out: 0, cache: 0 }, lastMsgId: null,
    events: [], lastKind: null, limit: null, files: [], todos: null, todosAt: null, todosAtMs: null,
    todoSource: null, segs: [], mode: null,
  };
}
function todoStatus(v) {
  return ['pending', 'in_progress', 'completed'].includes(v) ? v : 'pending';
}
// ponytail: session.agent basi (nilai awal); mode aktif = pesan terakhir. JANGAN pakai session.agent.
function modeOf(handle, sid) {
  let rows = [];
  try {
    rows = query(handle, `SELECT m.data AS mdata FROM message m WHERE m.session_id=? ORDER BY m.time_created DESC LIMIT 5`, [sid]);
  } catch { return null; }
  for (const row of rows) {
    let d;
    try { d = JSON.parse(row.mdata); } catch { continue; }
    if (!isPlainObj(d)) continue;
    if (d.role === 'assistant') {
      if (d.mode === 'compaction') continue;
      return d.mode === 'plan' ? 'plan' : d.mode === 'build' ? 'build' : null;
    }
    if (d.role === 'user') return d.agent === 'plan' ? 'plan' : d.agent === 'build' ? 'build' : null;
  }
  return null;
}
function int(v) {
  return typeof v === 'number' && Number.isFinite(v) ? Math.trunc(v) : 0;
}
function firstToken(cmd) {
  for (const tok of cmd.split(/[ \n]+/)) if (tok !== '') return tok;
  return '';
}
