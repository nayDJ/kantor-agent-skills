#!/usr/bin/env node
// Uji kesetaraan server PHP (public/index.php via php -S) dan Node (bin/serve-node.mjs) pada project & transkrip yang sama.
//   node bin/parity.mjs [--project=DIR] [--php-port=8791] [--node-port=8792] [--rounds=2] [--forbid=regex …]
// Membandingkan: JSON /kerja/api/state (kecuali "now"), window.KANTOR di /kerja, /kerja/api/ping (kecuali "runtime"),
// aset + ETag, header keamanan, 404 untuk path terlarang, 421 untuk Host asing, dan tidak ada rahasia yang bocor.
// Keluar 0 bila semua sama. Kedua server dimatikan di akhir.
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RUNTIME = fs.realpathSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..'));
const arg = (k, d) => process.argv.slice(2).filter((a) => a.startsWith(`--${k}=`)).map((a) => a.slice(k.length + 3)).pop() ?? d;
const PROJECT = fs.realpathSync(arg('project', process.cwd()));
const PHP_PORT = Number(arg('php-port', 8791));
const NODE_PORT = Number(arg('node-port', 8792));
const ROUNDS = Number(arg('rounds', 2));
const FORBID = [
  /\bsk-(?!•)[A-Za-z0-9_-]{8,}/, /\bghp_[A-Za-z0-9]{20,}/, /\bAKIA[0-9A-Z]{16}\b/,
  ...process.argv.slice(2).filter((a) => a.startsWith('--forbid=')).map((a) => new RegExp(a.slice(9))),
];
const STORE = fs.mkdtempSync(path.join(os.tmpdir(), 'kantor-parity-'));
const procs = [];
const problems = [];
const ok = [];
const cleanup = () => {
  for (const p of procs) {
    try {
      p.kill('SIGTERM');
    } catch {
      /* sudah berhenti */
    }
  }
  fs.rmSync(STORE, { recursive: true, force: true });
};
process.on('exit', cleanup);
for (const s of ['SIGINT', 'SIGTERM']) process.on(s, () => process.exit(130));

function start(cmd, args, env) {
  const p = spawn(cmd, args, { cwd: RUNTIME, env: { ...process.env, KANTOR_PROJECT: PROJECT, KANTOR_STORAGE: STORE, ...env }, stdio: ['ignore', 'ignore', 'pipe'] });
  let err = '';
  p.stderr.on('data', (d) => {
    err += d;
  });
  p.on('error', (e) => problems.push(`${cmd} tidak bisa dijalankan: ${e.message}`));
  procs.push(p);
  return () => err;
}
async function waitUp(base, name, errOf) {
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`${base}/kerja/api/ping`)).ok) return true;
    } catch {
      /* belum siap */
    }
    await new Promise((r) => setTimeout(r, 150));
  }
  problems.push(`${name} tidak siap di ${base}: ${errOf().slice(0, 400)}`);
  return false;
}
const get = (base, p, h = {}) => fetch(base + p, { redirect: 'manual', headers: h });
// fetch() tidak bisa mengganti header Host → pakai node:http
const hostGet = (port, p, host) => new Promise((resolve) => {
  const req = http.request({ host: '127.0.0.1', port, path: p, headers: { Host: host } }, (res) => {
    res.resume();
    resolve(res.statusCode);
  });
  req.on('error', () => resolve(0));
  req.end();
});
function diff(a, b, at = '$', ignore = new Set(), out = []) {
  if (out.length > 40) return out;
  const ta = Array.isArray(a) ? 'array' : a === null ? 'null' : typeof a;
  const tb = Array.isArray(b) ? 'array' : b === null ? 'null' : typeof b;
  if (ta !== tb) {
    out.push(`${at}: tipe ${ta} ≠ ${tb} (${JSON.stringify(a)?.slice(0, 80)} | ${JSON.stringify(b)?.slice(0, 80)})`);
    return out;
  }
  if (ta === 'array') {
    if (a.length !== b.length) out.push(`${at}: panjang ${a.length} ≠ ${b.length}`);
    for (let i = 0; i < Math.min(a.length, b.length); i++) diff(a[i], b[i], `${at}[${i}]`, ignore, out);
  } else if (ta === 'object') {
    const ka = Object.keys(a).filter((k) => !(at === '$' && ignore.has(k)));
    const kb = Object.keys(b).filter((k) => !(at === '$' && ignore.has(k)));
    if (ka.join('|') !== kb.join('|')) out.push(`${at}: kunci [${ka}] ≠ [${kb}]`);
    for (const k of ka) if (Object.hasOwn(b, k)) diff(a[k], b[k], `${at}.${k}`, ignore, out);
  } else if (a !== b) out.push(`${at}: ${JSON.stringify(a)?.slice(0, 100)} ≠ ${JSON.stringify(b)?.slice(0, 100)}`);
  return out;
}
function check(name, cond, detail = '') {
  if (cond) ok.push(name);
  else problems.push(`${name}${detail ? `: ${detail}` : ''}`);
}

const phpErr = start('php', ['-S', `127.0.0.1:${PHP_PORT}`, '-t', 'public', 'public/index.php'], {});
const nodeErr = start(process.execPath, ['bin/serve-node.mjs'], { KANTOR_PORT: String(NODE_PORT), KANTOR_BIND: '127.0.0.1' });
const P = `http://127.0.0.1:${PHP_PORT}`;
const N = `http://127.0.0.1:${NODE_PORT}`;
const up = (await waitUp(P, 'PHP', phpErr)) & (await waitUp(N, 'Node', nodeErr));

if (up) {
  let sp;
  let sn;
  for (let round = 1; round <= ROUNDS; round++) {
    const [rp, rn] = await Promise.all([get(P, '/kerja/api/state'), get(N, '/kerja/api/state')]);
    sp = await rp.json();
    sn = await rn.json();
    const d = diff(sp, sn, '$', new Set(['now']));
    check(`state ronde ${round} identik (${sp.runs?.length ?? 0} run, ${sp.feed?.length ?? 0} event, ${sp.freelancers?.length ?? 0} freelancer)`, d.length === 0, `\n    ${d.slice(0, 25).join('\n    ')}`);
  }
  const raw = JSON.stringify(sp) + JSON.stringify(sn);
  for (const re of FORBID) check(`tidak ada kebocoran ${re}`, !re.test(raw), (raw.match(re) || [''])[0].slice(0, 60));

  const [hp0, hn0] = await Promise.all([get(P, '/kerja/api/history'), get(N, '/kerja/api/history')]);
  const [hp0j, hn0j] = [await hp0.json(), await hn0.json()];
  const dh0 = diff(hp0j, hn0j);
  check(`/kerja/api/history identik (${hp0j.runs?.length ?? 0} run)`, hp0.status === 200 && hn0.status === 200 && dh0.length === 0, dh0.slice(0, 10).join('; '));
  const sinceY = new Date(Date.now() - 86400000).toISOString();
  const [hps, hns] = await Promise.all([get(P, `/kerja/api/history?since=${encodeURIComponent(sinceY)}`), get(N, `/kerja/api/history?since=${encodeURIComponent(sinceY)}`)]);
  const [hpsj, hnsj] = [await hps.json(), await hns.json()];
  const dhs = diff(hpsj, hnsj);
  check('history ?since= memfilter identik', hps.status === 200 && dhs.length === 0 && (hpsj.runs?.length ?? 0) <= (hp0j.runs?.length ?? 0), dhs.slice(0, 10).join('; '));
  const word = String(hp0j.runs?.[0]?.task ?? '').split(/\s+/).find((w) => w.length >= 3) ?? 'a';
  const [hpq, hnq] = await Promise.all([get(P, `/kerja/api/history?q=${encodeURIComponent(word)}`), get(N, `/kerja/api/history?q=${encodeURIComponent(word)}`)]);
  const [hpqj, hnqj] = [await hpq.json(), await hnq.json()];
  const dhq = diff(hpqj, hnqj);
  check(`history ?q=${word} memfilter identik`, hpq.status === 200 && dhq.length === 0 && (hpqj.runs?.length ?? 0) <= (hp0j.runs?.length ?? 0), dhq.slice(0, 10).join('; '));

  const [hp, hn] = await Promise.all([get(P, '/kerja'), get(N, '/kerja')]);
  const [tp, tn] = [await hp.text(), await hn.text()];
  check('/kerja 200 di keduanya', hp.status === 200 && hn.status === 200, `${hp.status}/${hn.status}`);
  const cfgOf = (t) => {
    const m = /window\.KANTOR = (.*?);<\/script>/s.exec(t);
    try {
      return m ? JSON.parse(m[1]) : null;
    } catch {
      return null;
    }
  };
  const cd = diff(cfgOf(tp), cfgOf(tn));
  check('window.KANTOR identik & valid', cfgOf(tp) !== null && cd.length === 0, cd.join('; '));
  check('<title> identik', (/<title>(.*?)<\/title>/.exec(tp) || [])[1] === (/<title>(.*?)<\/title>/.exec(tn) || [])[1]);
  for (const h of ['x-robots-tag', 'referrer-policy', 'x-content-type-options', 'x-frame-options', 'content-security-policy']) {
    check(`header ${h}`, !!hp.headers.get(h) && hp.headers.get(h) === hn.headers.get(h), `${hp.headers.get(h)} / ${hn.headers.get(h)}`);
  }
  const [pp, pn] = await Promise.all([get(P, '/kerja/api/ping'), get(N, '/kerja/api/ping')]);
  const [jp, jn] = [await pp.json(), await pn.json()];
  check('/kerja/api/ping identik (kecuali runtime)', jp.app === 'kantor-agent' && jp.project === jn.project && jp.runtime === 'php' && jn.runtime === 'node');
  const [ap, an] = await Promise.all([get(P, '/kerja/assets/kantor.js'), get(N, '/kerja/assets/kantor.js')]);
  check('/kerja/assets/kantor.js identik', ap.status === 200 && an.status === 200 && (await ap.text()) === (await an.text()));
  const etag = an.headers.get('etag');
  check('ETag sama & 304', etag === ap.headers.get('etag') && (await get(N, '/kerja/assets/kantor.js', { 'If-None-Match': etag })).status === 304
    && (await get(P, '/kerja/assets/kantor.js', { 'If-None-Match': etag })).status === 304);
  for (const bad of [
    '/kerja/assets/..%2F..%2Flib%2Fphp%2FConfig.php', '/kerja/assets/..%2Fdefaults.json', '/kerja/assets/%2e%2e/%2e%2e/defaults.json',
    '/kerja/assets/../../bin/kantor.sh', '/kerja/assets/vendor/three/LICENSE', '/kerja/api/doc?path=README.md', '/kerja/evidence/x.png',
    '/kerja/tidak-ada', '/lib/php/Config.php', '/defaults.json', '/views/page.html', '/public/index.php', '/kerja/assets/%00.js',
  ]) {
    const [bp, bn] = await Promise.all([get(P, bad), get(N, bad)]);
    check(`404 ${bad}`, bp.status === 404 && bn.status === 404, `PHP ${bp.status} / Node ${bn.status}`);
  }
  for (const [host, want] of [['jahat.contoh.test', 421], ['jahat.contoh.test:8788', 421], ['abc-def.trycloudflare.com', 200], ['localhost:8788', 200], ['192.168.1.5:8788', 200]]) {
    const [xp, xn] = await Promise.all([hostGet(PHP_PORT, '/kerja/api/ping', host), hostGet(NODE_PORT, '/kerja/api/ping', host)]);
    check(`Host ${host} → ${want}`, xp === want && xn === want, `PHP ${xp} / Node ${xn}`);
  }
  const [mp, mn] = await Promise.all([fetch(`${P}/kerja/api/state`, { method: 'POST' }), fetch(`${N}/kerja/api/state`, { method: 'POST' })]);
  check('POST ditolak (405)', mp.status === 405 && mn.status === 405, `PHP ${mp.status} / Node ${mn.status}`);
  const [rp, rn] = await Promise.all([get(P, '/'), get(N, '/')]);
  check('/ → 302 /kerja', rp.status === 302 && rn.status === 302 && rp.headers.get('location') === '/kerja' && rn.headers.get('location') === '/kerja');

  // multi-project: kedua server dengan KANTOR_PROJECTS yang sama (project berisi + project kosong)
  const EMPTY = fs.mkdtempSync(path.join(os.tmpdir(), 'kantor-parity-empty-'));
  const LIST = `${PROJECT}\n${EMPTY}`;
  const PP2 = PHP_PORT + 10;
  const NP2 = NODE_PORT + 10;
  const phpErr2 = start('php', ['-S', `127.0.0.1:${PP2}`, '-t', 'public', 'public/index.php'], { KANTOR_PROJECTS: LIST });
  const nodeErr2 = start(process.execPath, ['bin/serve-node.mjs'], { KANTOR_PORT: String(NP2), KANTOR_BIND: '127.0.0.1', KANTOR_PROJECTS: LIST });
  const P2 = `http://127.0.0.1:${PP2}`;
  const N2 = `http://127.0.0.1:${NP2}`;
  const up2 = (await waitUp(P2, 'PHP-multi', phpErr2)) & (await waitUp(N2, 'Node-multi', nodeErr2));
  if (up2) {
    const [jp2, jn2] = [await (await get(P2, '/kerja/api/projects')).json(), await (await get(N2, '/kerja/api/projects')).json()];
    const dp = diff(jp2, jn2);
    check(`/kerja/api/projects identik (${jp2.projects?.length ?? 0} project)`, dp.length === 0, dp.join('; '));
    const emptyId = jp2.projects?.[1]?.id;
    check('projects berisi 2 entri + id 12 char', jp2.projects?.length === 2 && /^[0-9a-f]{12}$/.test(jp2.projects[0].id) && /^[0-9a-f]{12}$/.test(emptyId ?? ''));
    if (emptyId) {
      const [ep, en] = await Promise.all([get(P2, `/kerja/api/state?project=${emptyId}`), get(N2, `/kerja/api/state?project=${emptyId}`)]);
      const [sp2, sn2] = [await ep.json(), await en.json()];
      const de = diff(sp2, sn2, '$', new Set(['now']));
      check('state project kosong identik', de.length === 0, de.slice(0, 10).join('; '));
      const [rp2, rn2] = await Promise.all([get(P2, `/kerja/api/state?room=${emptyId}`), get(N2, `/kerja/api/state?room=${emptyId}`)]);
      const [srp, srn] = [await rp2.json(), await rn2.json()];
      const dr = diff(srp, srn, '$', new Set(['now']));
      check('state ?room= identik Node≡PHP', dr.length === 0, dr.slice(0, 10).join('; '));
      const [wp, wn] = await Promise.all([get(P2, `/kerja?project=${emptyId}`), get(N2, `/kerja?project=${emptyId}`)]);
      const [qp, qn] = [await wp.text(), await wn.text()];
      check('/kerja?project= KANTOR identik', wp.status === 200 && wn.status === 200 && diff(cfgOf(qp), cfgOf(qn)).length === 0);
      const [xp, xn] = await Promise.all([get(P2, `/kerja?project=junk`), get(N2, `/kerja?project=junk`)]);
      const [yp, yn] = [await xp.text(), await xn.text()];
      check('project asing → default pertama', xp.status === 200 && JSON.stringify(cfgOf(yp)) === JSON.stringify(cfgOf(yn))
        && cfgOf(yp)?.projects?.length === 2);
      const [gq, gk] = [await (await get(P2, '/kerja/api/ping')).json(), await (await get(N2, '/kerja/api/ping')).json()];
      check('ping hub identik', gq.project === gk.project && gq.project !== jp2.projects[0].id);
    }
  }
  try {
    fs.rmSync(EMPTY, { recursive: true, force: true });
  } catch {
    /* abaikan */
  }

  // claude fixture: 1 sesi utama + 1 subagent (agent-*.jsonl + .meta.json) via CLAUDE_CONFIG_DIR sintetis
  const CFIX = fs.mkdtempSync(path.join(os.tmpdir(), 'kantor-parity-claude-'));
  const munged = PROJECT.replace(/[^a-zA-Z0-9]/g, '-');
  const cRoot = path.join(CFIX, 'projects', munged);
  const cSub = path.join(cRoot, 'sess-claude', 'subagents');
  fs.mkdirSync(cSub, { recursive: true });
  const t0 = new Date(Date.now() - 60000).toISOString();
  const t1 = new Date().toISOString();
  const mainLines = [
    JSON.stringify({ type: 'user', timestamp: t0, message: { role: 'user', content: 'halo' }, isSidechain: false }),
    JSON.stringify({ type: 'assistant', timestamp: t1, message: { id: 'm1', role: 'assistant', content: [{ type: 'text', text: 'oke' }], stop_reason: 'end_turn', usage: { input_tokens: 10, output_tokens: 5, cache_read_input_tokens: 0, cache_creation_input_tokens: 0 } } }),
  ].join('\n') + '\n';
  fs.writeFileSync(path.join(cRoot, 'main-claude.jsonl'), mainLines);
  fs.writeFileSync(path.join(cSub, 'agent-abc123.jsonl'), mainLines);
  fs.writeFileSync(path.join(cSub, 'agent-abc123.meta.json'), JSON.stringify({ agentType: 'Explore', description: 'cari file', parentAgentId: null }));
  const PP3 = PHP_PORT + 20;
  const NP3 = NODE_PORT + 20;
  const phpErr3 = start('php', ['-S', `127.0.0.1:${PP3}`, '-t', 'public', 'public/index.php'], { CLAUDE_CONFIG_DIR: CFIX });
  const nodeErr3 = start(process.execPath, ['bin/serve-node.mjs'], { KANTOR_PORT: String(NP3), KANTOR_BIND: '127.0.0.1', CLAUDE_CONFIG_DIR: CFIX });
  const P3 = `http://127.0.0.1:${PP3}`;
  const N3 = `http://127.0.0.1:${NP3}`;
  const up3 = (await waitUp(P3, 'PHP-claude', phpErr3)) & (await waitUp(N3, 'Node-claude', nodeErr3));
  if (up3) {
    const [cp, cn] = await Promise.all([get(P3, '/kerja/api/state'), get(N3, '/kerja/api/state')]);
    const [sp3, sn3] = [await cp.json(), await cn.json()];
    const dc = diff(sp3, sn3, '$', new Set(['now']));
    check(`state fixture Claude identik (${sp3.runs?.length ?? 0} run)`, dc.length === 0, dc.slice(0, 10).join('; '));
    const hasC = (s) => (s.runs || []).some((r) => r.task === 'cari file' && r.agent_type === 'Explore');
    check('state ronde mencakup run Claude', hasC(sp3) && hasC(sn3), `PHP ${hasC(sp3)} / Node ${hasC(sn3)}`);
  }
  try {
    fs.rmSync(CFIX, { recursive: true, force: true });
  } catch {
    /* abaikan */
  }

  // omp fixture: 1 sesi utama + 1 subagent subdir via OMP_SESSIONS_DIR sintetis
  const OFIX = fs.mkdtempSync(path.join(os.tmpdir(), 'kantor-parity-omp-'));
  const canon = (p) => {
    try {
      return fs.realpathSync(String(p));
    } catch {
      return path.resolve(String(p));
    }
  };
  const relName = (prefix, rel) => {
    const enc = rel.replace(/[/\\:]/g, '-');
    return enc ? (prefix.endsWith('-') ? `${prefix}${enc}` : `${prefix}-${enc}`) : prefix;
  };
  const encodeBucket = (cwd) => {
    const c = canon(cwd);
    const relH = path.relative(canon(os.homedir()), c);
    if (relH === '' || (!relH.startsWith('..') && !path.isAbsolute(relH))) return relName('-', relH);
    const relT = path.relative(canon(os.tmpdir()), c);
    if (relT === '' || (!relT.startsWith('..') && !path.isAbsolute(relT))) return relName('-tmp', relT);
    return `--${c.replace(/^[/\\]/, '').replace(/[/\\:]/g, '-')}--`;
  };
  const oRoot = path.join(OFIX, encodeBucket(PROJECT));
  const oBase = '20240101_120000_abc999';
  const oSub = path.join(oRoot, oBase);
  fs.mkdirSync(oSub, { recursive: true });
  const ot0 = new Date(Date.now() - 60000).toISOString();
  const ot1 = new Date().toISOString();
  const oMain = [
    JSON.stringify({ type: 'session', id: 'omp-main-1', cwd: PROJECT, title: 'OMP utama' }),
    JSON.stringify({ type: 'message', id: 'm1', timestamp: ot0, message: { role: 'user', content: [{ type: 'text', text: 'halo' }] } }),
    JSON.stringify({ type: 'message', id: 'm2', timestamp: ot1, message: { role: 'assistant', content: [{ type: 'text', text: 'oke' }], stopReason: 'stop', usage: { input: 10, output: 5, cacheRead: 0, cacheWrite: 0 } } }),
  ].join('\n') + '\n';
  const oSubLines = [
    JSON.stringify({ type: 'session', id: 'omp-sub-1', cwd: PROJECT }),
    JSON.stringify({ type: 'title', title: 'omp-tugas-khusus-xyz' }),
    JSON.stringify({ type: 'message', id: 's1', timestamp: ot0, message: { role: 'user', content: [{ type: 'text', text: 'kerja' }] } }),
    JSON.stringify({ type: 'message', id: 's2', timestamp: ot1, message: { role: 'assistant', content: [{ type: 'text', text: 'beres' }], stopReason: 'stop', usage: { input: 3, output: 2, cacheRead: 0, cacheWrite: 0 } } }),
  ].join('\n') + '\n';
  fs.writeFileSync(path.join(oRoot, `${oBase}.jsonl`), oMain);
  fs.writeFileSync(path.join(oSub, 'AgentOmpA.jsonl'), oSubLines);
  const PP4 = PHP_PORT + 30;
  const NP4 = NODE_PORT + 30;
  const phpErr4 = start('php', ['-S', `127.0.0.1:${PP4}`, '-t', 'public', 'public/index.php'], { OMP_SESSIONS_DIR: OFIX });
  const nodeErr4 = start(process.execPath, ['bin/serve-node.mjs'], { KANTOR_PORT: String(NP4), KANTOR_BIND: '127.0.0.1', OMP_SESSIONS_DIR: OFIX });
  const P4 = `http://127.0.0.1:${PP4}`;
  const N4 = `http://127.0.0.1:${NP4}`;
  const up4 = (await waitUp(P4, 'PHP-omp', phpErr4)) & (await waitUp(N4, 'Node-omp', nodeErr4));
  if (up4) {
    const [op, on] = await Promise.all([get(P4, '/kerja/api/state'), get(N4, '/kerja/api/state')]);
    const [sp4, sn4] = [await op.json(), await on.json()];
    const d4 = diff(sp4, sn4, '$', new Set(['now']));
    check(`state fixture OMP identik (${sp4.runs?.length ?? 0} run)`, d4.length === 0, d4.slice(0, 10).join('; '));
    const hasO = (s) => (s.runs || []).some((r) => r.task === 'omp-tugas-khusus-xyz');
    check('state memuat runs OMP', hasO(sp4) && hasO(sn4), `PHP ${hasO(sp4)} / Node ${hasO(sn4)}`);
  }
  try {
    fs.rmSync(OFIX, { recursive: true, force: true });
  } catch {
    /* abaikan */
  }
}

cleanup();
for (const o of ok) console.log(`  ok   ${o}`);
for (const p of problems) console.log(`  BEDA ${p}`);
console.log(problems.length ? `PARITY GAGAL (${problems.length} masalah, ${ok.length} ok)` : `PARITY OK (${ok.length} pemeriksaan)`);
process.exit(problems.length ? 1 : 0);
