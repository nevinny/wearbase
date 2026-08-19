#!/usr/bin/env bash
# Полноразмерные стоп-кадры на заданных секундах — для чтения мелкого UI-текста
# (browse-кадры из grab.sh для этого слишком мелкие).
set -euo pipefail

FFMPEG=/opt/homebrew/bin/ffmpeg

VID=${1:-}
OUT=${2:-}
shift 2 || true
[ -n "$VID" ] && [ -n "$OUT" ] && [ $# -gt 0 ] || {
  echo "usage: still.sh <видео> <outdir> <сек> [сек...]" >&2
  exit 1
}
mkdir -p "$OUT"

for t in "$@"; do
  f=$(printf '%s/still_%06.1f.jpg' "$OUT" "$t")
  "$FFMPEG" -nostdin -v error -ss "$t" -i "$VID" -frames:v 1 -q:v 2 -y "$f"
  echo "$f"
done
