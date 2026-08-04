---
name: yt-ingest
description: Загнать YouTube-видео в RAG базы знаний (Qdrant topic_chunks) — субтитры/whisper → чистый txt → app:kb:ingest-channels. Use when «загони видео в RAG», «добавь в базу знаний советника», ссылка на YouTube с просьбой «в RAG/в базу», «добавь канал в базу знаний».
---

# YouTube-видео → RAG (topic_chunks)

Переиспользует штатный конвейер YT-базы знаний: транскрипт в `~/yt-kb/txt/<channel>/<video_id>.txt`,
ингест — `app:kb:ingest-channels` (чанкинг → qwen3-embedding → Qdrant `topic_chunks`).
Идемпотентно: точки UUIDv5 от `channel:video_id:chunk_index`, повторный прогон = upsert, не дубли.

`PHP=/opt/homebrew/bin/php`. Эмбеддер и Qdrant — на LLM-сервере (`ssh llm`); эмбеддер ≠ gemma,
параллелить с брендовым конвейером можно.

## Шаги

1. **Метаданные видео**:
   ```sh
   yt-dlp --skip-download --print "%(id)s|%(uploader_id)s|%(channel)s|%(duration)s|%(title)s" '<URL>'
   ```
   `uploader_id` без `@` = ключ канала (совпадает с каталогами `~/yt-kb/txt/`).

2. **Канал в реестре?** Ключи — `config/knowledge/channels.yaml`. Если канала нет:
   определи роль по содержанию (`idea` — бизнес-идеи/рост, `framing` — мышление/фреймы,
   `case` — разборы кейсов, `tone` — стиль/коммуникация, `seo` — SEO/директории),
   добавь строку в yaml по образцу соседних и скажи в ответе, какую роль выбрал и почему.
   Неочевидно — спроси пользователя. Правка yaml = изменение репо → ветка + PR как обычно.

3. **Транскрипт — сначала субтитры** (быстро, без GPU):
   ```sh
   D=$(mktemp -d "$TMPDIR/ytkb.XXXXXX")
   yt-dlp --skip-download --write-subs --write-auto-subs --sub-langs "ru,ru-orig,en" \
     --sub-format vtt -o "$D/%(id)s.%(ext)s" '<URL>'
   ```
   Приоритет файлов: ручные `ru` > авто `ru` > `ru-orig` > `en`. Очистка:
   ```sh
   python3 .claude/skills/yt-ingest/vtt2txt.py "$D/<video_id>.ru.vtt" > "$D/<video_id>.txt"
   ```

4. **Нет субтитров → whisper-фолбэк** (как в скилле media-transcribe):
   ```sh
   yt-dlp -f 'ba/b' -x --audio-format wav -o "$D/audio.%(ext)s" '<URL>'
   ffmpeg -y -i "$D"/audio.wav -ar 16000 -ac 1 "$D/audio16.wav"
   whisper-cli -m "$HOME/tg-bots/agent-router/models/ggml-large-v3-turbo.bin" \
     -f "$D/audio16.wav" -l auto -np -otxt -of "$D/<video_id>"
   ```
   ~15–60 сек на минуту аудио: видео длиннее ~20 мин — только фоном (`run_in_background`) с логом.

5. **Sanity + архив**: txt непустой (обычно ≥2–3K знаков; меньше 500 — что-то не так, посмотри глазами).
   Затем в постоянный архив (txt обязательно, vtt — если был):
   ```sh
   mkdir -p ~/yt-kb/txt/<channel> ~/yt-kb/raw/<channel>
   cp "$D/<video_id>.txt" ~/yt-kb/txt/<channel>/
   cp "$D"/<video_id>.*.vtt ~/yt-kb/raw/<channel>/ 2>/dev/null || true
   ```

6. **Точечный ингест** — через `--path` на временный корень с одним файлом
   (без `--path` команда переembедит весь каталог канала):
   ```sh
   T=$(mktemp -d "$TMPDIR/ytkb-ingest.XXXXXX") && mkdir "$T/<channel>" && cp "$D/<video_id>.txt" "$T/<channel>/"
   /opt/homebrew/bin/php -d memory_limit=512M bin/console app:kb:ingest-channels \
     --path="$T" --channel=<channel> --no-debug
   ```
   Ошибка «Qdrant недоступен» → LLM-сервер лежит или переехал IP — скилл llm-server.
   ⚠️ Никогда не передавать `--recreate` — снесёт всю коллекцию topic_chunks.

7. **Проверка**: в таблице команды «Файлов 1, чанков N, точек N», пропущено 0.
   Убрать за собой: `rm -rf "$D" "$T"`.

Ответ ОДНИМ сообщением: канал/роль, откуда транскрипт (субтитры или whisper), сколько чанков/точек,
и что видео теперь ретривится советником (роль = фильтр ретрива).

## Пачка видео / целый канал

Тот же флоу: все txt складывать в один временный корень `$T/<channel>/` и ингестить одной командой.
Целый канал (десятки видео) — субтитры качать батчем `yt-dlp --skip-download ... '<URL канала>/videos'`,
ингест затем штатно без `--path`: `app:kb:ingest-channels --channel=<channel>` по постоянному архиву
(upsert, уже залитое не задублируется).
