---
name: kantor-agent
description: Jalankan "Kantor Agent" — kantor 3D (three.js) tanpa konfigurasi di http://127.0.0.1:8788/kerja yang menampilkan apa yang sedang dikerjakan OpenCode di project ini. Ketua (sesi utama) bekerja di mejanya; 4 anggota tim (Budi, Sari, Agus, Rina) santai di lounge lalu duduk bekerja setiap kali OpenCode memanggil subagent (jenis apa pun); bila tim penuh, freelancer datang lewat pintu. Semua dari database sesi OpenCode lokal + sesi Kiro CLI (`~/.kiro/sessions/cli`), read-only, tanpa menulis file ke project. Node ≥ 18 (atau PHP ≥ 8.1). Pakai saat user ingin melihat/menyalakan/mematikan kantor agent, dashboard subagent, atau URL publiknya.
argument-hint: "[start|stop|status|publik|tutup-publik] [--node|--php] [--port N]"
---

Runtime siap pakai ada di `runtime/` sebelah file SKILL.md ini — **tidak ada yang disalin ke project**. Semua perintah dijalankan
dari folder berisi SKILL.md ini (pakai path relatif terhadapnya, contoh `bash "runtime/bin/kantor.sh" <perintah>`) dengan
**root project saat ini** sebagai working directory (folder tempat OpenCode dibuka), karena sesi dicari berdasarkan path folder itu.

Argumen user: $ARGUMENTS

## Langkah
1. **Pilih perintah dari argumen** (tanpa argumen = `start`):
   - `start` / kosong → `bash "runtime/bin/kantor.sh" start` (teruskan `--node`, `--php`, `--port N`,
     `--project DIR` (boleh diulang untuk multi-project) bila ada).
   - `stop` → `… kantor.sh stop` · `status` → `… kantor.sh status` · `restart` → `… kantor.sh restart`.
   - `publik` / `tunnel` / `--publik` → jalankan `start` dulu, lalu `… kantor.sh tunnel` (butuh `cloudflared`).
   - `tutup-publik` / `tunnel-stop` → `… kantor.sh tunnel-stop`.
2. Skrip bersifat idempoten: bila server sudah berjalan untuk project ini ia hanya mencetak URL; port sibuk → otomatis
   pindah ke port bebas berikutnya; Node ≥ 18 dipakai bila ada, selain itu PHP ≥ 8.1. Bila keduanya tidak ada, sampaikan
   pesan error skrip apa adanya (cara memasang Node/PHP) lalu berhenti.
3. **Laporkan singkat** (Bahasa Indonesia): URL lokal (baris `URL :`), runtime, cara menghentikan
   (`/kantor-agent stop` atau perintah `Hentikan` yang dicetak). Bila skrip mencetak `Catatan : belum ada transkrip…`,
   jelaskan bahwa kantor terisi setelah OpenCode dipakai di folder ini (semua karakter santai sampai ada aktivitas).
4. **Tawarkan URL publik dalam satu kalimat** (jangan dijalankan tanpa persetujuan eksplisit): "Ingin dibuka dari ponsel
   di luar jaringan? Jalankan `/kantor-agent publik`." Saat user memintanya, setelah `tunnel` berhasil sampaikan
   peringatannya: siapa pun yang tahu link bisa melihat aktivitas agent (read-only, diredaksi), bagikan hanya ke orang
   tepercaya, dan matikan dengan `/kantor-agent tutup-publik`.

## Cara membaca kantornya (untuk menjawab pertanyaan user)
- **Ketua** (default "Joko") = sesi utama OpenCode terbaru yang aktif; lebih dari satu sesi aktif → catatan "+N sesi lain".
- **Tim** (Budi, Sari, Agus, Rina) = subagent. Subagent baru → anggota bebas pertama (urutan tetap) berjalan ke mejanya;
  selesai/dihentikan/limit → "Beres!" lalu kembali santai. Penugasan dihitung ulang dari data yang sama sehingga stabil.
- **Freelancer** = subagent saat keempat anggota tim sibuk: masuk lewat pintu, duduk di meja cadangan (maks 4 meja,
  sisanya kartu "+N"), pulang lewat pintu setelah selesai.
- Nama/judul/port bisa ditimpa lewat `<project>/.opencode/kantor-agent.json` (opsional, lihat README):
  `{"title": "…", "port": 8788, "names": {"ketua": "…", "team": ["…","…","…","…"], "freelancers": ["…"]}, "autostart": false}`.
- Multi-project (`--project` diulang): tiap project = satu ruangan dengan Ketua + tim sendiri;
  klik ruangan/dropdown/`?room=` untuk fokus, panel (kartu, feed, riwayat, tugas) mengikuti ruangan fokus.
- Notifikasi browser opt-in lewat tombol di bar atas; bunyi sekali per transisi (subagent selesai/limit/terhenti, Ketua aktif kembali) hanya saat tab tersembunyi.
- Tombol salin ringkasan (`Sesi YYYY-MM-DD: <Ketua> + N subagent, M tool calls, T token`) serta tab Aktivitas (sparkline 24 batang per 2 jam, 48 jam terakhir) mengikuti ruangan fokus.
- Stat token Ketua absolut di bar atas; batasnya pengingat manual (klik stat untuk ubah, `localStorage`, default 100000) — bukan limit provider.

## Aturan
- Read-only terhadap project dan transkrip; jangan membuat atau mengubah file di project kecuali user meminta
  `.opencode/kantor-agent.json`. Cache/PID/log ada di `~/.cache/kantor-agent/<slug-project>/`.
- Jangan pernah membuka tunnel publik tanpa persetujuan user; jangan menyalin isi transkrip ke balasan.
- Autostart (hook SessionStart plugin) mati secara bawaan. Aktifkan hanya bila user meminta: `"autostart": true` di
  `.opencode/kantor-agent.json` (atau env `KANTOR_AUTOSTART=1`).
- Sumber data: OpenCode (`OPENCODE_DB`, default `~/.local/share/opencode/opencode.db`) dan Kiro CLI (`KIRO_SESSIONS_DIR`, default `~/.kiro/sessions/cli`).
- Verifikasi opsional: `node "runtime/bin/check.mjs" <url-dasar>`; bila Node **dan** PHP ada:
  `node "runtime/bin/parity.mjs" --project="$PWD"` (harus "PARITY OK").
