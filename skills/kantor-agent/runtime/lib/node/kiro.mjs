// Sesi Kiro CLI (~/.kiro/sessions/cli/*.json + *.jsonl) — read-only.
// Isi Prompt user & ToolResults TIDAK PERNAH dibaca. Port PHP: lib/php/Kiro.php (dicek bin/parity.mjs).
//   <id>.json                                header {session_id, cwd, created_at, updated_at, title, session_created_reason}
//   <id>.jsonl                               baris {kind: Prompt|AssistantMessage|ToolResults|Compaction}
// Subagent = session_created_reason "subagent" (atau agent parent). Waktu baris hanya ada di Prompt
// (meta.timestamp, detik) — baris lain memakai waktu Prompt terakhir.
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
  basename, clip, isDir, isFile, isPlainObj, isoMs, mbLen, phpTrim, readText, redact,
  oneLine, safeLine, strcmp, tsMs, values,
} from './util.mjs';

const CACHE_V = 1;
const EVENTS_KEEP = 40;
const SEGS_KEEP = 20;
const FILES_KEEP = 12;
const TODOS_KEEP = 40;

// ponytail: dir ditemukan sekali per proses; pindah KIRO_SESSIONS_DIR perlu restart server.
let dirMemo = null;

export function kiroDir() {
  if (dirMemo !== null) return dirMemo;
  const env = process.env.KIRO_SESSIONS_DIR || '';
  if (env !== '') return (dirMemo = env);
  return (dirMemo = `${(process.env.HOME || os.homedir() || '').replace(/\/+$/, '')}/.kiro/sessions/cli`);
}

// ponytail: cwd→toplevel (null ikut dicache agar git gagal tidak diulang).
// show-toplevel sama untuk subdir, tapi BEDA per worktree → common-dir (absolut)
// menutupi linked worktree: satu .git utama untuk main + semua worktree-nya.
const topCache = new Map();
function gitOut(dir, args) {
  return String(execFileSync('git', ['-C', dir, ...args], { timeout: 5000, stdio: 'pipe', encoding: 'utf8' })).trim() || null;
}
function topLevel(dir) {
  if (typeof dir !== 'string' || dir === '') return null;
  if (topCache.has(dir)) return topCache.get(dir);
  let top = null;
  try {
    top = gitOut(dir, ['rev-parse', '--show-toplevel']);
  } catch {
    top = null;
  }
  topCache.set(dir, top);
  return top;
}
const commonCache = new Map();
function commonDir(dir) {
  if (typeof dir !== 'string' || dir === '') return null;
  if (commonCache.has(dir)) return commonCache.get(dir);
  let c = null;
  try {
    c = gitOut(dir, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
  } catch {
    c = null;
  }
  commonCache.set(dir, c);
  return c;
}
function sameRepo(a, b, projTop, projCommon) {
  const t = topLevel(a);
  if (t !== null && projTop !== null && t === projTop) return true;
  const c = commonDir(a);
  return c !== null && projCommon !== null && c === projCommon;
}

let lastGood = null; // ponytail: file terkunci/rusak → hasil bagus terakhir, bukan kantor kosong.

export class Kiro {
  constructor(projectDir, storageDir, cfg) {
    this.projectDir = projectDir;
    this.cacheDir = storageDir ? path.join(storageDir, 'cache') : null;
    this.cfg = cfg;
  }

  scan(nowSec) {
    if (!isDir(kiroDir())) return { exists: false, runs: [], mains: [] };
    try {
      return (lastGood = this.scanDir(nowSec));
    } catch {
      return lastGood ?? { exists: true, runs: [], mains: [] };
    }
  }

  scanDir(nowSec) {
    const cutoff = (nowSec - this.cfg.window_days * 86400) * 1000;
    let names;
    try {
      names = fs.readdirSync(kiroDir()).filter((n) => n.endsWith('.json') && !n.startsWith('.')).sort(strcmp);
    } catch {
      return { exists: true, runs: [], mains: [] };
    }
    const mains = [];
    const runs = [];
    const projTop = topLevel(this.projectDir);
    const projCommon = commonDir(this.projectDir);
    for (const n of names) {
      let h;
      try {
        h = JSON.parse(readText(path.join(kiroDir(), n)) ?? '');
      } catch {
        continue;
      }
      if (!isPlainObj(h) || typeof h.session_id !== 'string') continue;
      // ponytail: worktree = cwd beda tapi satu repo (toplevel untuk subdir, common-dir untuk worktree).
      if (h.cwd !== this.projectDir && !sameRepo(h.cwd, this.projectDir, projTop, projCommon)) continue;
      const upd = tsMs(h.updated_at);
      if (upd === null || upd < cutoff) continue;
      if (mains.length + runs.length >= this.cfg.mains_max * 2) break;
      const sum = this.summarize(h);
      const item = {
        ...sum,
        session: String(h.session_id),
        provider: 'kiro',
        agentType: clip(agentName(h) ?? 'general-purpose', 40),
        description: typeof h.title === 'string' ? safeLine(h.title, 140) : '',
      };
      if (isSub(h)) {
        // ponytail: kiro tidak mencatat sesi induk → peminta selalu Ketua (parentAgent null).
        item.id = String(h.session_id);
        item.parentAgent = null;
        if (item.started !== null) runs.push(item);
      } else if (mains.length < this.cfg.mains_max) {
        mains.push(item);
      }
    }
    runs.sort((a, b) => (a.started < b.started ? -1 : a.started > b.started ? 1 : strcmp(a.id, b.id)));
    return { exists: true, runs, mains };
  }

  summarize(h) {
    const sid = String(h.session_id);
    const key = `k-${crypto.createHash('md5').update(sid).digest('hex')}.json`;
    const cacheFile = this.cacheDir ? path.join(this.cacheDir, key) : null;
    let s = null;
    if (cacheFile && isFile(cacheFile)) {
      try {
        const c = JSON.parse(readText(cacheFile) ?? '');
        // ponytail: sesi tidak berubah (updated_at sama) → pakai cache.
        if (isPlainObj(c) && c.v === CACHE_V && c.updated === h.updated_at) s = c.s;
      } catch { s = null; }
    }
    if (s === null) {
      s = fresh();
      const jl = path.join(kiroDir(), `${sid}.jsonl`);
      const raw = readText(jl);
      if (raw !== null) {
        for (const line of raw.split('\n')) {
          if (phpTrim(line) === '') continue;
          let row;
          try {
            row = JSON.parse(line);
          } catch {
            continue;
          }
          if (isPlainObj(row)) this.consume(s, row);
        }
      }
      delete s.cur;
      const last = s.segs[s.segs.length - 1];
      if (s.started !== null && last && last[1] === null && s.updated !== null) last[1] = s.updated;
      if (cacheFile) {
        try {
          fs.mkdirSync(path.dirname(cacheFile), { recursive: true });
          fs.writeFileSync(cacheFile, JSON.stringify({ v: CACHE_V, updated: h.updated_at, s }));
        } catch { /* cache opsional */ }
      }
    }
    return {
      started: s.started, updated: s.updated, tools: s.tools, tokens: 0,
      events: s.events, lastKind: s.lastKind, limit: s.limit, files: s.files, todos: s.todos, todosAt: s.todosAt,
      todoSource: s.todoSource, segs: s.segs, stops: [],
    };
  }

  consume(s, row) {
    const kind = row.kind ?? '';
    const data = isPlainObj(row.data) ? row.data : {};
    if (kind === 'Prompt') {
      // ponytail: satu-satunya baris bertimestamp; isi prompt tidak pernah ditampilkan.
      const ts = data.meta && Number.isFinite(Number(data.meta.timestamp)) ? Number(data.meta.timestamp) * 1000 : null;
      if (ts !== null) {
        s.cur = ts;
        const t = isoMs(ts);
        if (s.started === null) {
          s.started = t;
          s.segs.push([t, null]);
        }
        s.updated = t;
        if (!s.segs.length) s.segs.push([t, null]);
        this.push(s, t, 'user', 'Instruksi dari user', null);
        s.lastKind = 'user';
      }
      return;
    }
    if (kind !== 'AssistantMessage') return; // ToolResults/Compaction: privasi — dilewati total.
    if (s.cur === undefined || s.started === null) return;
    const t = isoMs(s.cur);
    s.updated = t;
    if (!s.segs.length) s.segs.push([t, null]);
    // ponytail: tiap pesan = satu seg; digabung bila jeda ≤ cooldown supaya satu sesi = satu job.
    const last = s.segs[s.segs.length - 1];
    if (last[1] !== null) {
      if (s.cur - Date.parse(last[1]) <= this.cfg.cooldown * 1000) last[1] = null;
      else {
        s.segs.push([t, null]);
        if (s.segs.length > SEGS_KEEP) s.segs.shift();
      }
    }
    for (const b of Array.isArray(data.content) ? data.content : []) {
      if (!isPlainObj(b)) continue;
      const bt = b.kind ?? '';
      const bd = isPlainObj(b.data) ? b.data : {};
      if (bt === 'text') {
        const txt = typeof bd === 'string' ? phpTrim(bd) : phpTrim(String(bd.text ?? ''));
        if (txt === '') continue;
        if (/(usage limit|rate limit|limit reached|resets? (at|in))/i.test(txt) && mbLen(txt) < 400) s.limit = clip(redact(txt), 200);
        this.push(s, t, 'text', safeLine(txt, 180), null);
        s.lastKind = 'text';
      } else if (bt === 'toolUse') {
        const name = typeof bd.name === 'string' ? bd.name : '?';
        const inp = isPlainObj(bd.input) ? bd.input : {};
        s.tools++;
        const [text, files] = this.describeTool(name, inp);
        for (const f of files) {
          s.files = s.files.filter((x) => x !== f);
          s.files.push(f);
          if (s.files.length > FILES_KEEP) s.files.shift();
        }
        if (name === 'todo_list' && Array.isArray(inp.tasks)) {
          s.todos = inp.tasks.filter((td) => isPlainObj(td) && phpTrim(String(td.task_description ?? '')) !== '')
            .slice(0, TODOS_KEEP).map((td) => ({ text: safeLine(String(td.task_description), 120), status: todoStatus(td.status ?? td.task_status) }));
          s.todosAt = t;
          s.todoSource = 'TodoWrite';
        }
        this.push(s, t, 'tool', text, name);
        s.lastKind = 'tool';
        s.limit = null;
      }
      // thinking: tanpa event.
    }
  }

  push(s, t, kind, text, tool) {
    s.events.push({ t, kind, text, tool });
    if (s.events.length > EVENTS_KEEP) s.events.splice(0, s.events.length - EVENTS_KEEP);
  }

  describeTool(name, inp) {
    const str = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isInteger(v) ? String(v) : '');
    const rel = (p) => (p.startsWith(`${this.projectDir}/`) ? p.slice(this.projectDir.length + 1) : basename(p));
    let text;
    let files = [];
    switch (name) {
      case 'read': {
        const ops = Array.isArray(inp.operations) ? inp.operations.filter(isPlainObj) : [];
        const first = ops.find((o) => typeof o.path === 'string');
        text = `Membaca ${first ? rel(first.path) : ''}`;
        files = ops.filter((o) => o.mode === 'File' && typeof o.path === 'string').map((o) => rel(o.path));
        break;
      }
      case 'write':
        text = `Menulis ${typeof inp.path === 'string' ? rel(inp.path) : ''}`;
        if (typeof inp.path === 'string' && inp.path !== '') files = [rel(inp.path)];
        break;
      // ponytail: argumen shell tidak pernah ditampilkan — purpose saja.
      case 'shell': text = `Menjalankan: ${str(inp.__tool_use_purpose) !== '' ? str(inp.__tool_use_purpose) : `${firstToken(str(inp.command))} …`}`; break;
      case 'grep': text = `Mencari '${clip(str(inp.pattern), 50)}'`; break;
      case 'glob': text = `Mencari file ${clip(str(inp.pattern), 60)}`; break;
      case 'todo_list': text = 'Memperbarui daftar tugas'; break;
      case 'switch_to_execution': text = 'Melanjutkan ke eksekusi'; break;
      case 'introspect': text = `Mencari '${clip(str(inp.query), 50)}'`; break;
      default: text = clip(name, 60);
    }
    return [oneLine(text, 160), files.slice(0, FILES_KEEP)];
  }
}

function isSub(h) {
  if (h.session_created_reason === 'subagent') return true;
  for (const m of values(h.session_state?.conversation_metadata?.user_turn_metadatas)) {
    const a = m?.loop_id?.agent_id;
    if (isPlainObj(a) && a.parent_id !== null && a.parent_id !== undefined) return true;
  }
  return false;
}

function agentName(h) {
  for (const m of values(h.session_state?.conversation_metadata?.user_turn_metadatas)) {
    const a = m?.loop_id?.agent_id;
    if (isPlainObj(a) && typeof a.name === 'string' && phpTrim(a.name) !== '') return phpTrim(a.name);
  }
  return null;
}

function fresh() {
  return {
    started: null, updated: null, tools: 0, events: [], lastKind: null, limit: null,
    files: [], todos: null, todosAt: null, todoSource: null, segs: [], cur: undefined,
  };
}
function todoStatus(v) {
  return ['pending', 'in_progress', 'completed'].includes(v) ? v : 'pending';
}
function firstToken(cmd) {
  for (const tok of cmd.split(/[ \n]+/)) if (tok !== '') return tok;
  return '';
}
