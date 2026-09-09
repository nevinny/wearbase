---
id: 0003
title: 20 опубликованных иностранных брендов с мусором в видимом тексте — конвейер их не починит
status: ждёт решения
owner: не назначен
source: сессия 2026-09-07 (ключевики + гео-хабы)
created: 2026-09-07
updated: 2026-09-07
---

## Суть

`app:brand:keywords-purge` нашёл 55 брендов с вхождениями регекспа `порно|porno|onlyfans|only fans|xxx|нюдс|nudes` в `brand.description`/`brand.anons`/`brand_faq` (наследие генераций до блоклиста минус-слов). 54 — мусор в тексте, 18 — в FAQ. Перегенерация доступна только 22 брендам: 32 отсечены гейтами конвейера (`brand.niche_status='off'` либо `brand.origin_status IN ('foreign','unknown')` — `PipelineQueueRepository::findRegenFlagged` гоняет через `applyGates()`). Из этих 32 гейт-заблокированных **20 сейчас status=active (опубликованы на проде)**.

Список 20 id/slug: 703 merkurii (niche=off), 1063 anny, 1233 bwell, 1278 banzo, 1483 bogner, 1630 camelactive, 1738 chloe, 2052 ditavonteese, 2237 encharm, 2726 halston, 2790 heys, 2931 intimissimi, 3377 lee, 3400 leonmedikal, 3713 maniitalacitta, 3741 marcotozzi, 4982 roxy (origin=foreign), 5493 terranova, 5730 underarmour, 6833 mercier. Остальные 12 гейт-заблокированных — status=new, не опубликованы.

Почти все 20 — иностранные марки, которые по политике проекта вообще не должны быть опубликованы (память `foreign-brands-policy`; у большинства `origin_status='unknown'`, у roxy — `foreign`).

## Почему это важно

На проде живут страницы с мусорным текстом (порно-лексика в description/anons/FAQ) у иностранных брендов, которые и без этого нарушают политику непубликации. Гейты конвейера, придуманные для защиты от залива воды, здесь работают как блокер починки: чинить конвейером нельзя, а руками — не делали.

## Где смотреть

- проверочный запрос: регексп выше по `brand.description`/`brand.anons` + join `brand_faq`
- `src/Repository/PipelineQueueRepository.php` — `findRegenFlagged()`, `applyGates()`
- память `foreign-brands-policy` (Nike/Chanel/Gucci и т.п. не публикуем)
- ниша-гейт и tombstone/410 — см. память `wearbase-niche-gate`

## Что сделать

- [ ] решить направление: снять 20 с публикации через существующий tombstone/410 ниша-гейта, либо чинить текст руками бренд за брендом
- [ ] добить бэкафилл `origin_status` по этим 20: `app:brand:origin-check --force --id=<id>`
- [ ] проверить, не осталось ли ещё опубликованных иностранцев вне этого списка 55 (регексп ловит только порно-лексику, не origin в целом)

## Заметки

Решение внешне видимое (снятие страниц с прода) — поэтому в сессии находки не выполнено.
