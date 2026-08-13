#!/usr/bin/env bash
# Кадры видео + контактные листы для дешёвого триажа vision-агентом.
# Homebrew-ffmpeg собран БЕЗ drawtext (freetype), ImageMagick не установлен → таймкоды
# не выжигаются в кадр, а считаются по формуле из MANIFEST.md.
set -euo pipefail

FFMPEG=/opt/homebrew/bin/ffmpeg
FFPROBE=/opt/homebrew/bin/ffprobe
YTDLP=/opt/homebrew/bin/yt-dlp
COLS=5
ROWS=5

SRC=${1:-}
INT=${2:-3}
OUT=${3:-}
[ -n "$SRC" ] || { echo "usage: grab.sh <URL|файл> [интервал_сек=3] [outdir]" >&2; exit 1; }

if [ -z "$OUT" ]; then
  slug=$(basename "$SRC" | tr -cd 'A-Za-z0-9_-' | tail -c 24)
  OUT="${TMPDIR:-/tmp}/vidframes-${slug:-clip}"
fi
mkdir -p "$OUT/frames" "$OUT/sheets"

# 1) источник — идемпотентно, уже скачанное не тянем второй раз
if [[ "$SRC" == http*://* ]]; then
  VID="$OUT/video.mp4"
  if [ ! -s "$VID" ]; then
    "$YTDLP" -f 'bv*[height<=1080][ext=mp4]+ba/b[height<=1080]' --merge-output-format mp4 \
      -o "$VID" "$SRC"
  fi
else
  VID="$SRC"
fi
[ -s "$VID" ] || { echo "нет видео: $VID" >&2; exit 1; }

DUR=$("$FFPROBE" -v error -show_entries format=duration -of csv=p=0 "$VID" | cut -d. -f1)

# 2) browse-кадры 960px: хватает понять «что на экране», НЕ хватает прочитать мелкий UI-текст
if [ -z "$(ls -A "$OUT/frames")" ]; then
  "$FFMPEG" -nostdin -v error -i "$VID" -vf "fps=1/$INT,scale=960:-2" -q:v 4 "$OUT/frames/f%04d.jpg"
fi
N=$(ls "$OUT/frames" | wc -l | tr -d ' ')

# 3) контактные листы. tile выбрасывает неполную последнюю плитку → добиваем копиями
#    последнего кадра через staging-симлинки, чтобы frames/ остался чистым.
PER=$((COLS * ROWS))
REM=$((N % PER))
PAD=0
[ "$REM" -ne 0 ] && PAD=$((PER - REM))
STAGE="$OUT/.stage"
rm -rf "$STAGE"
mkdir -p "$STAGE"
i=0
for f in "$OUT"/frames/f*.jpg; do
  i=$((i + 1))
  ln -sf "$f" "$(printf '%s/p%04d.jpg' "$STAGE" "$i")"
done
LAST=$(printf '%s/frames/f%04d.jpg' "$OUT" "$N")
for j in $(seq 1 "$PAD" 2>/dev/null || true); do
  ln -sf "$LAST" "$(printf '%s/p%04d.jpg' "$STAGE" "$((N + j))")"
done
rm -f "$OUT"/sheets/*.jpg
"$FFMPEG" -nostdin -v error -framerate 1 -i "$STAGE/p%04d.jpg" \
  -vf "scale=384:-2,tile=${COLS}x${ROWS}:margin=6:padding=4:color=0x202020" \
  -q:v 3 "$OUT/sheets/s%03d.jpg"
rm -rf "$STAGE"
SHEETS=$(ls "$OUT/sheets" | wc -l | tr -d ' ')

SKILLDIR=$(cd "$(dirname "$0")" && pwd)
cat > "$OUT/MANIFEST.md" <<EOF
# vid-frames

источник: $SRC
видео: $VID (${DUR} сек)
интервал: ${INT} сек · кадров: $N (browse, 960px) · листов: $SHEETS (сетка ${COLS}x${ROWS}, порядок построчный)
последний лист добит копиями кадра f$(printf '%04d' "$N") — $PAD шт. (tile режет неполную плитку)

Таймкод кадра:  t = (номер_кадра - 1) * $INT
Кадр по ячейке: кадр = (лист - 1) * $PER + ячейка   (лист и ячейка нумеруются с 1)
Таймкод ячейки: t = ((лист - 1) * $PER + ячейка - 1) * $INT

Полноразмерный стоп-кадр (для чтения мелкого UI-текста):
  bash $SKILLDIR/still.sh "$VID" "$OUT/stills" <сек> [сек...]
EOF

echo "OUT=$OUT"
echo "кадров=$N листов=$SHEETS интервал=${INT}s длительность=${DUR}s"
echo "листы: $OUT/sheets/  манифест: $OUT/MANIFEST.md"
