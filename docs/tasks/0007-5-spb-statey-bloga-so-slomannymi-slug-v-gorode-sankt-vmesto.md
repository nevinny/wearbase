---
id: 0007
title: 5 СПб-статей блога со сломанными slug: -v-gorode-sankt- вместо -sankt-peterburg-
status: ждёт решения
owner: не назначен
source: сессия 2026-09-07 (ключевики + гео-хабы)
created: 2026-09-07
updated: 2026-09-07
---

## Суть

5 статей блога про Санкт-Петербург со сломанным slug (название города обрезано до «sankt»):

- id 28 `vintazh-gid-po-brendam-v-gorode-sankt-2026` — сломан и slug, и тайтл («в городе Санкт»)
- id 27 `ulichnyi-stil-gid-po-brendam-v-gorode-sankt-2026` — тайтл целый, сломан только slug
- `top-3-brendov-minimalizm-sankt-reiting-2026`
- `top-3-brendov-vintazh-sankt-reiting-2026`
- `top-4-brendov-povsednevnyi-sankt-reiting-2026`

Причины разные: у id 28 обрезано само название города на вызове (`--city=санкт`), у остальных — обрезка при формировании slug. Генератор гайдов — `src/Command/SeoGuideCommand.php` (тайтл строится в строках 139–140, 200–201 из значения `--city`), рейтинги — `src/Command/SeoRankingCommand.php`.

Одна из них ранжируется: `top-3-brendov-minimalizm-sankt-reiting-2026` — 18 показов, 2 клика, средняя позиция 3.4 (GSC, 28 дней). `ulichnyi-stil-...-sankt-2026` — 14 показов, 1 клик, позиция 8.1.

## Почему это важно

Одна из сломанных страниц уже держит позицию 3.4 в выдаче — смена slug без 301 обнулит накопленный сигнал. Плюс за пределами этих 5 генератор продолжит плодить такие же битые URL для новых гео-статей, если не починить.

## Где смотреть

- `src/Command/SeoGuideCommand.php` — тайтл, строки 139–140, 200–201
- `src/Command/SeoRankingCommand.php` — рейтинги
- GSC (28 дней): `top-3-brendov-minimalizm-sankt-reiting-2026` 18 показов/2 клика/поз. 3.4; `ulichnyi-stil-gid-po-brendam-v-gorode-sankt-2026` 14 показов/1 клик/поз. 8.1
- память `no-force-overwrite-ready-articles`

## Что сделать

- [ ] найти точное место обрезки названия города в обоих генераторах (`SeoGuideCommand`, `SeoRankingCommand`)
- [ ] решить судьбу существующих 5 URL: переезд на правильный slug требует 301 (особенно для `top-3-brendov-minimalizm-sankt-reiting-2026` с позицией 3.4)
- [ ] починить генератор до выпуска новых гео-статей по городам с составным названием

## Заметки

Смена URL у страницы с позицией 3.4 — внешне видимое SEO-решение, поэтому не выполнено в сессии находки. `--force` поверх готовых статей запрещён (память `no-force-overwrite-ready-articles`) — правка существующих 5 URL должна идти через 301-редирект на новый slug, а не через перезапись контента по старому.
