# ComfyUI-конвейер видео-генерации — разбор рабочего сетапа (референс)

**Что это.** Реверс рабочего пайплайна из скринкаста «Коля Майнер»
([lqO6UBxCiEo](https://www.youtube.com/watch?v=lqO6UBxCiEo), 30 мин) — снято покадрово скиллом
`.claude/skills/vid-frames` (кадр/3 сек → триаж → полноразмерные стоп-кадры). Не пересказ речи:
всё ниже прочитано **с экрана**, таймкоды даны для проверки глазами.

Зачем нам: это самый близкий к нашему product-motion Reels готовый сетап (оживление фото +
русская речь в кадре). Стек и экономика — [`marketing_instagram.md`](marketing_instagram.md) §5.

## 1. Набор воркфлоу = набор табов ComfyUI (15:15)

Одна инсталляция, восемь открытых воркфлоу — по сути готовая раскладка ролей:

| Таб | Роль |
|---|---|
| `minimax_h3_i2v` | image-to-video MiniMax H3 (видео + речь + эмбиент одной нодой) |
| `video_minimax_h3_r…` | ref-to-video H3 (тот самый «R2V» — чекпойнт Ref2VA) |
| `video_ltx2_3_ia2v` | image+audio-to-video LTX-2.3 (быстрый дубль) |
| `LTX-2.3-LipSync-Pit…` | липсинк под готовую звуковую дорожку |
| `CosyVoice-TTS` | клон голоса + TTS (см. §3) |
| `omnivoice-tts_exam…`, `audio_ace_step1_5_…` | альтернативные TTS/аудио |
| `SeedVR2_HD_video…` | **апскейл видео** (в речи звучит как «CVR» — на экране это SeedVR2) |

Вывод для нас: «сгенерить клип» — это не одна модель, а 3–4 воркфлоу подряд
(картинка → видео → голос/липсинк → апскейл). Наш `ReelsSlideshowRenderer` останется финальной
сборкой; AI-часть встаёт **до** него, отдавая mp4-кадры.

## 2. MiniMax H3 image-to-video: что именно в графе (15:15, 17:40, 21:30)

Цепочка: `Load Image` (реф-фото, 941×1672) → `Image to Video (MiniMax H3)` → `Save Video`,
плюс `Resolution Selector` и note-нода со справочником разрешений.

**Промпт — три именованных блока в одном поле** (ключевая находка, у нас так не делается):

```
[VISUAL]: A locked-off, static medium close-up shot of the blonde woman from the reference
image, sitting at the desk facing the camera directly. …zero head shaking. Only her lips move…
[SPEECH]: "Всем привет… с вами Оля Майнер…" spoken in Russian with an energetic, confident
female voice, studio quality, clear pronunciation and zero background noise.
[SOUNDS]: Subtle indoor room ambience.
```

То есть модель омни-модальная: `[VISUAL]` держит камеру статичной, `[SPEECH]` задаёт язык/тембр
и **саму реплику**, `[SOUNDS]` — эмбиент. Отдельный TTS для говорящей головы не обязателен.

**Виджеты ноды** (имена файлов — точные, с экрана):

| Виджет | Значение |
|---|---|
| `duration` | 6.0 / 8.0 / 10.0 сек |
| `unet_name` | `minimax_h3_fl2va_pruned_int8_convrot.safetensors` |
| `clip_name` | `qwen3vl_32b_minimax_h3_nvfp4_awq.safetensors` |
| `vae_name` | `minimax_h3_video_vae_fp16.safetensors` |
| `audio_vae` | `minimax_h3_audio_vae_fp32.safetensors` |
| `noise_seed` | фиксируется для повторяемости дублей |

Текст-энкодер — **квантованный qwen3-vl-32b (nvfp4-awq)**, то есть «текст-энкодеры на десятки
гигабайт» из речи — это он.

**`Resolution Selector`**: `aspect 9:16 (Portrait Widescreen)`, `megapixels 0.9`, `multiple 32`.
Справочная нода рядом даёт всю сетку мегапиксели→разрешение (16:9; для 9:16 стороны меняются):

| MP | Разрешение | MP | Разрешение |
|---|---|---|---|
| 0.2 | 608×352 | 0.9 | **1280×736** |
| 0.3 | 736×416 | 0.98 | 1344×768 |
| 0.4 | 864×480 | 1.0 | 1376×768 |
| 0.5 | 960×544 | 1.2 | 1504×832 |
| 0.6 | 1056×608 | 1.5 | 1664×928 |
| 0.7 | 1152×640 | 1.8 | 1824×1024 |
| 0.8 | 1216×672 | 2.0 | 1920×1088 |

Там же подсказка про нативный канвас H3: короткая сторона **768 px**, кратность 32, длина в
кадрах считается блоками `17k+5` при 24 fps. Значит «наши» 1080×1920 — это уже апскейл,
генерить надо в 0.9 MP и поднимать SeedVR2.

**Измеренная нагрузка при генерации (17:40)**: GPU 100%, **VRAM 60%** от 32 ГБ ≈ **19 ГБ**,
RAM 65%, temp 72°. То есть int8-сборка H3 реально просит ~19–20 ГБ VRAM → **влезает в 24 ГБ**
карту, а не только в 32 ГБ. Готовый клип в очереди: `MiniMax_H3_00017.mp4 — 664.02 s`
(≈11 мин, совпадает с речью). Прогресс идёт через `SamplerCustomAdvanced`.

## 3. Клон голоса CosyVoice3 (27:00)

Отдельный воркфлоу, если нужен **свой** голос, а не выдуманный `[SPEECH]`-тембр:

`FL CosyVoice3 Audio Crop` (обрезка референса, `start_time 0:00` / `end_time 0:07–0:10` — хватает
7–10 секунд) → `FL CosyVoice3 Save Speaker` (`speaker_name`, сохраняет в
`models/cosyvoice/speaker/<name>.pt`) → `FL CosyVoice3 Instruct2` (текст + инструкция стиля,
`speed 1.00`, `seed`, `control_after_generate randomize`, `text_frontend true`) → `Save Audio`.

Инструкция стиля задаётся прозой в отдельном поле: *«Speak in a highly expressive, dramatic tone,
with distinct pauses between words and strong emotional emphasis»*. Один раз сохранил спикера —
дальше синтез любых реплик этим голосом; дорожка затем идёт в `LTX-2.3-LipSync`.

Для нас применимо только к **своему** голосу/диктору бренда с согласием — клонировать чужие
голоса не делаем (в кадре у автора лежит спикер `Trump.pt`; нам такое нельзя ни юридически,
ни по позиционированию §5).

## 4. Clore.ai — что видно в кабинете (04:45–05:30)

- Свои риги в аренде: `Rig3` = 1× **RTX 4090 (23.99 GB)**, 16/32 CPU, **63 GB RAM**,
  ставка **2.7 USD/день**; всего 5 серверов в листинге.
- Прайс из их же доков: **RTX 4090 от $0.50/день** («vs $2–4 у облачных провайдеров»), карты
  вообще — **от $0.15/день**; 3400+ машин, 12800+ GPU.
- Подключение своего сервера — одна строка `bash <(curl -s …/hosting-agent-installer/…)`,
  работает на Ubuntu и HiveOS.
- В гайдах Clore под видео: CogVideoX, LTX-Video Real-Time, Stable Video Diffusion, Wan2.1,
  Wan 2.2 VBVR (motion control), OpenSora, FramePack, **LTX-2 (Audio+Video)**, SkyReels-V3,
  AnimateDiff, Mochi-1; обработка — FFmpeg NVENC, RIFE Interpolation. **H3 в гайдах ещё нет**
  (модель открыли 03.08.2026) — ставить придётся руками.

⚠️ Правка к §5: ориентир «$4/день за 5090» — со слов автора. По кабинету и докам аренда
**дешевле** ($0.5–2.7/день за 4090), т.е. вывод «батч на арендованной карте против $0.18 за клип
у fal.ai» только усиливается.

## 5. Что забрать в наш конвейер

1. **Промпт-схема `[VISUAL]/[SPEECH]/[SOUNDS]`** — если делаем говорящий кадр, это готовый
   шаблон; `[VISUAL]` с «locked-off, static, only lips move» — рецепт против дрожащей головы.
2. **Генерить в 0.9 MP (1280×736 / 736×1280), апскейлить SeedVR2** — не пытаться сразу в 1080p.
3. **Фиксировать `noise_seed`** и гнать 10 быстрых дублей LTX-2.3 вместо одного H3 (§5, тактика).
4. **Логотип и числа — оверлеем поверх готового mp4** (правило §5 не меняется: текст внутри
   генерации ломается).
5. Порог входа по VRAM: **~19–20 ГБ на H3-int8** → арендная 4090/24 ГБ достаточна; наш бокс
   не подходит по RAM/диску ([`llm_infra_handoff.md`](llm_infra_handoff.md) §2).

## 6. Как переснять/проверить

```sh
bash .claude/skills/vid-frames/grab.sh 'https://www.youtube.com/watch?v=lqO6UBxCiEo' 3
# затем стоп-кадры интересных секунд:
bash .claude/skills/vid-frames/still.sh "$OUT/video.mp4" "$OUT/stills" 915 1060 1290 1620
```

Ключевые таймкоды: **04:45–05:30** Clore-кабинет · **15:15** граф H3 i2v целиком ·
**17:40** телеметрия во время генерации + справочник разрешений · **21:30** готовый клип и
Media Assets · **27:00** CosyVoice3-клон голоса.
