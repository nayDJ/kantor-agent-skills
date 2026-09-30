#!/usr/bin/env bash
set -u
REPO="$(cd "$(dirname "$0")" && pwd)"
SRC="$REPO/skills/kantor-agent"
[ -d "$SRC" ] || { echo "Tidak ada $SRC — jalankan dari folder repo (./install.sh)" >&2; exit 1; }
COPY=0; OO=0; KO=0; PROJ=0; UN=0
for a in "$@"; do case "$a" in
  --copy) COPY=1;;
  --opencode-only) OO=1;;
  --kiro-only) KO=1;;
  --project) PROJ=1;;
  --uninstall) UN=1;;
  -h|--help) echo "Pakai: ./install.sh [--copy] [--opencode-only] [--kiro-only] [--project] [--uninstall]"; exit 0;;
  *) echo "Flag tidak dikenal: $a (lihat ./install.sh --help)" >&2; exit 1;;
esac; done
[ "$OO" = 1 ] && [ "$KO" = 1 ] && { OO=0; KO=0; }
DST=""
if [ "$PROJ" = 1 ]; then
  [ "$OO" = 0 ] && [ "$KO" = 0 ] && DST="$PWD/.opencode/skills/kantor-agent $PWD/.kiro/skills/kantor-agent"
  [ "$OO" = 1 ] && DST="$PWD/.opencode/skills/kantor-agent"
  [ "$KO" = 1 ] && DST="$PWD/.kiro/skills/kantor-agent"
else
  OB="${XDG_CONFIG_HOME:-$HOME/.config}/opencode/skills/kantor-agent"; KB="$HOME/.kiro/skills/kantor-agent"
  [ "$OO" = 0 ] && [ "$KO" = 0 ] && DST="$OB $KB"
  [ "$OO" = 1 ] && DST="$OB"
  [ "$KO" = 1 ] && DST="$KB"
fi
pasang() {
  if [ "$COPY" = 1 ]; then rm -rf "$1"; mkdir -p "$(dirname "$1")"; cp -R "$SRC" "$1"; echo "Disalin: $1"
  elif [ -L "$1" ] && [ "$(readlink "$1")" = "$SRC" ]; then echo "Sudah benar, lewati: $1"
  else rm -rf "$1"; mkdir -p "$(dirname "$1")"; ln -s "$SRC" "$1"; echo "Taut dipasang: $1"; fi
}
hapus() {
  if [ -e "$1" ] || [ -L "$1" ]; then rm -rf "$1"; echo "Dihapus: $1"; else echo "Tidak ada, lewati: $1"; fi
}
# shellcheck disable=SC2086
if [ "$UN" = 1 ]; then for d in $DST; do hapus "$d"; done; exit 0; fi
# shellcheck disable=SC2086
for d in $DST; do pasang "$d"; done
bash "$SRC/runtime/bin/kantor.sh" detect
