# Озвученные рилсы на Remotion (пилот 2026-10-09)

Плашка = фраза голоса целиком (читается без звука с кадра 0), текущее слово подсвечено по таймингам whisper.cpp.
Remotion рисует видеоряд без звука; ffmpeg кладёт голос и подложку (на `bedUnderVoiceDb` ниже голоса), длина задаётся явно (`-t`, без `-shortest`).
Потом `qa-transcript.cjs` по готовому mp4 (код 1 = брак). Пишется `manifest.review.json`, а не `manifest.json`: очередь и daily его не видят.

```bash
# 1) дубль голоса: qwen.cjs prepareVoice (ровно 5 сцен = scenes[].voice из спеки) -> take.wav + take.json
# 2) рендер
node scripts/wardrobe-reels/remotion/render-voiced.cjs scripts/wardrobe-reels/remotion/specs/<id>.json \
  --wav take.wav --voice-json take.json --take D [--root <checkout с var/ и public_html/>]
```

- `node_modules` в репо нет: при первом запуске ставится симлинк на `~/tools/reels-motion/remotion/node_modules` (или `REMOTION_NODE_MODULES`).
- Спека: `scenes[].voice` — текст для TTS (ё явно, без омографов и меток ударения, без цифр); `image` — файл из `assets` или `04.mp4@1.5` (стоп-кадр);
  `splitAtWord` — смена плашки внутри сцены; `push`/`mark` — наезд и рамка на экране продукта (координаты кадра 1080×1920); `buttonFromWord` — хвост CTA уходит в кнопку.
- Сид Qwen зафиксирован (1062026), поэтому «дубли» = варианты текста (кеш на риге по тексту).

Пилот t31 v2 (дубль D): whisper 4 с, Remotion 12 с (18.6 с ролика), mux 0.3 с, всего ~22 с wall. QA 35/36 слов: «тёте» whisper слышит как «тетя».
