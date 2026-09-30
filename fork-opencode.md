# Fork OpenCode + Kiro — Kantor Agent

Fork dari `humaedihume/kantor-agent` (Claude Code) → target: **OpenCode + Kiro CLI, digabung satu kantor**.

Pemilik repo asal: **humaedihume**. Fork diperbarui dan dikustomisasi menjadi skill plug-n-play oleh **Nayaka Bagus Djiwangga**.

## Keputusan
- Provider: **OpenCode + Kiro sekaligus** (tanpa abstraksi berlebih — dua pembaca konkret, satu bentuk output).
- Paritas: **Node + PHP identik** (gerbang: `parity.mjs` hijau sebelum merge).
- Subagent OpenCode = **child session (`parent_id`) + `tool=task`**; subagent Kiro = `session_created_reason "subagent"`.
- Mode: **ponytail ultra** — satu implementasi per sumber, tanpa dep baru, tanpa fallback config.

## Sumber data (terbukti lokal)
- DB: `~/.local/share/opencode/opencode.db` (SQLite WAL, read-only `mode=ro`), override `OPENCODE_DB`.
- Tabel: `session(id, project_id, parent_id, directory, title, agent, time_created/updated ms, tokens_*, time_archived)`, `message`, `part`, `todo`, `project_directory`.
- `part.data.type`: `text | reasoning | tool | step-start | step-finish | patch`.
- Tool: `read, edit, bash, glob, grep, write, task, todowrite, question, webfetch, websearch`.
- Filter sesi: `session.directory = PROJECT` (worktree/`project_directory` ditunda).
- Mapping minimal: `step-start` = mulai, `step-finish` = selesai, `updated + running_window` = timeout. `TaskStop/handback` ala Claude dihapus (tidak ada di adapter OpenCode/Kiro); deteksi `limit` dipertahankan di kedua adapter.

## Rencana → status: selesai
1. ~~`lib/node/transcripts.mjs`: baca SQLite~~ → selesai (`lib/node/transcripts.mjs` + `lib/php/Transcripts.php`).
2. ~~`kantor.sh`~~ → selesai (path DB, hitungan sesi OpenCode + Kiro, pesan baru).
3. ~~`SKILL.md`~~ → selesai + `README.md` ditulis ulang untuk fork ini.
4. ~~`parity.mjs` hijau → port PHP~~ → `PARITY OK` di 2 project.
5. ~~Hapus sisa Claude~~ → kode Claude dihapus dari runtime; docs upstream tinggal sebagai riwayat.

## Tidak dikerjakan
Dual-provider, flag `--provider`, fallback `.claude/`, worktree mapping (via `project_directory`), realtime subscribe, auth.

## Update 2026-09-30 — Kiro CLI gabung
- Sumber kedua: `~/.kiro/sessions/cli/*.json + *.jsonl` (`KIRO_SESSIONS_DIR` override), read-only.
- Filter project: `cwd == PROJECT`. Subagent = `session_created_reason "subagent"` / agent `parent_id`.
- Waktu baris hanya di `Prompt` (`meta.timestamp`, detik); baris lain ikut Prompt terakhir. `ToolResults` tidak dibaca.
- File baru: `lib/node/kiro.mjs` + `lib/php/Kiro.php`; `scan()` gabung OpenCode+Kiro, office tak berubah.
- `kantor.sh status/detect` tampilkan hitungan Kiro. PARITY OK di 2 project.
- Ketua multi-sesi: sesi utama aktif lain tampil sebagai `other_sessions` ("+N sesi lain").
- Fallback env: DB ikut `XDG_DATA_HOME`/`HOME`, project ikut `OPENCODE_WORKSPACE_ROOT`, runtime ikut `KANTOR_*` (lihat `kantor.sh`).
