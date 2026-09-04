---
id: 0002
title: Нет защиты контента забранного бренда: logoLocked есть, для description/anons аналога нет
status: новая
owner: не назначен
source: проверка регистрации clothesmurka@gmail.com, 2026-09-04
created: 2026-09-04
updated: 2026-09-04
---

## Суть

У логотипа есть защита от перезаписи конвейером: `brand.logo_locked` → `BrandIngestService` (src/Service/Agent/BrandIngestService.php:238) пропускает агент-пуш. Для текстового контента (`description`, `anons`, мета) аналога не нашлось.

Гипотеза: если владелец забранного бренда правит описание в ЛК, следующий прогон `generate-content` / агент-пуш с Mac может затереть правку. Не подтверждено — нужен разбор `GenerateBrandContentCommand` + `BrandIngestService` на предмет условия «у бренда есть BrandUser».

## Почему это важно

Первый же реальный владелец (бренд 2779 «Александр Мурка», забран 2026-09-04) сейчас имеет описание, сгенерированное RAG-конвейером. Его правки могут молча откатываться — самый обидный класс баг для платящего пользователя.

## Где смотреть

- `src/Service/Agent/BrandIngestService.php` — есть ли guard по владельцу помимо `isLogoLocked()`
- `src/Command/GenerateBrandContentCommand.php` — берёт ли в выборку бренды с `BrandUser`
- `src/Controller/Brands/BrandsController.php:295` — как ставится `logoLocked` (образец подхода)

## Что сделать

- [ ] подтвердить или опровергнуть перезапись (прогнать generate-content на бренде 2779 в dry-run)
- [ ] если подтвердится — гейт «у бренда есть владелец → контент не трогаем» либо поле-аналог `content_locked`

## Заметки

Найдено попутно при проверке регистрации clothesmurka@gmail.com; в PR #192 намеренно не заводилось (не относится к правке guard'а заявок).
