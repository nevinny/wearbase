---
id: 0004
title: 19 брендов с мусором в тексте ждут корпуса: реген уходит в deferred
status: новая
owner: не назначен
source: сессия 2026-09-07 (ключевики + гео-хабы)
created: 2026-09-07
updated: 2026-09-07
---

## Суть

Прогон `app:brand:generate-content 22 --regen-flagged` (07.09.2026): сгенерировано заново 2 бренда, **отложено 19** («grounded-only: корпус не прошёл gate → deferred»), 1 ошибка LLM. `--regen-flagged` принудительно включает `groundedOnly=true` (`GenerateBrandContentCommand`, строка ~220) — без годного корпуса описание не перезаписывается. Это защита от залива воды, а не баг. Мусор в тексте после прогона: было 54 бренда (42 опубликованных) → стало 51 (39).

## Почему это важно

19 брендов с мусорным текстом (см. задачу 0003) остаются в таком виде, пока для них нет годного корпуса — без discover/fetch/embed реген не сдвинется с места сам по себе, будет копиться в очереди.

## Где смотреть

- `src/Command/GenerateBrandContentCommand.php` — строка ~220 (`groundedOnly=true` при `--regen-flagged`), строка ~297 (short-circuit `meta-only` при описании ≥400 символов)
- `src/Command/PurgeKeywordJunkCommand.php` — строка ~205 (подсказка советует неверную команду)
- флаг `brand.regen_requested_at`

## Что сделать

- [ ] прогнать по 19 брендам `discover → fetch → embed`
- [ ] после этого повторный реген через флаг `regen_requested_at` + `--regen-flagged`
- [ ] проверить, почему корпус не набирается (мёртвые источники → `app:brand:rediscover`)
- [ ] поправить подсказку в `PurgeKeywordJunkCommand` (строка ~205) — советует неверную команду для перегенерации

## Заметки

Важная деталь на будущее: `app:brand:generate-content --id=X --force` описание НЕ переписывает при описании ≥400 символов (short-circuit `meta-only`, строка 297 `GenerateBrandContentCommand`). Единственный путь полной перегенерации — флаг `regen_requested_at` + `--regen-flagged`.
