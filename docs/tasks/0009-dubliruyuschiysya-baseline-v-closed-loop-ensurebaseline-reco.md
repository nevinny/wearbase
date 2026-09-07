---
id: 0009
title: Дублирующийся baseline в closed-loop: ensureBaseline+record без flush между ними подменяет снимок «до» на новый контент
status: новая
owner: не назначен
source: сессия 2026-09-07 (гео-хабы)
created: 2026-09-07
updated: 2026-09-07
---

## Суть

`GenerateBrandContentCommand` вызывает `$this->versioner->ensureBaseline($brand)`, затем перезаписывает `brand.*` новым контентом, затем вызывает `$this->versioner->record(...)` — и только после этого `$this->em->flush()`. `record()` внутри **снова** вызывает `ensureBaseline()`. Обе проверки «есть ли уже история» (`BrandContentRevisionRepository::hasAny()` / `findActive()`) идут через `findOneBy()`, то есть реальным SQL-запросом в БД, а не через in-memory unit of work Doctrine. Между двумя вызовами `ensureBaseline()` ничего не флашится.

Итог для бренда, у которого истории ревизий действительно ещё нет: первый `ensureBaseline()` (до перезаписи) готовит baseline-снимок со старым контентом, но не флашит его. Второй `ensureBaseline()` (внутри `record()`, уже после перезаписи `brand.*`) снова спрашивает БД, снова не видит истории (первый снимок ещё не в БД) и создаёт **второй** «baseline», но `snapshot()` в этот момент читает уже НОВЫЙ `brand.*` — то есть второй baseline фиксирует не то состояние, которое должно быть «до».

## Почему это важно

Baseline — это опорная точка отката closed-loop эксперимента («что было до генерации»). Если у баг сработал, откат (`rollback()` берёт `findRollbackTarget()`/конкретную ревизию по id) может вернуть бренду тот же новый текст, который и должен был замениться — то есть откат станет no-op при видимости «откат выполнен». Ошибка тихая: проявится только через 28 дней (`WINDOW_BY_ATTEMPT[1]`) на вердикте `loss` в `app:seo:evaluate-experiments`, когда контент реально нужно будет откатить.

## Где смотреть

- `src/Command/GenerateBrandContentCommand.php:541` — `ensureBaseline($brand)` ДО перезаписи (комментарий "снять старое (legacy) ДО перезаписи — не теряем")
- `src/Command/GenerateBrandContentCommand.php:542-544` — перезапись `brand.*` (description/meta/generated-флаги)
- `src/Command/GenerateBrandContentCommand.php:546` — `record(...)`, `flush()` только на следующей строке (547)
- тот же паттерн короче, без промежуточной перезаписи между вызовами — meta-only путь: `src/Command/GenerateBrandContentCommand.php:349` (`ensureBaseline`) → 351 (`record`)
- `src/Service/BrandContentVersioner.php:49-60` — `ensureBaseline()`: гейт `if ($this->repo->hasAny($brand)) return;` (строка 51)
- `src/Service/BrandContentVersioner.php:66-95` — `record()`: строка 73 — повторный вызов `$this->ensureBaseline($brand)`; строка 74 — `$prev = $this->repo->findActive($brand)` (тоже БД-запрос, не UoW)
- `src/Service/BrandContentVersioner.php:21` — сама реализация декларирует контракт "не делает flush — ответственность вызывающего батча", то есть двойной вызов без flush между ними — ожидаемый по контракту сценарий, а не экзотика
- `src/Repository/BrandContentRevisionRepository.php` — `hasAny()` и `findActive()` оба через `findOneBy()` (реальный SQL, не проверка identity map)
- Городская ветка **на первый взгляд не наступает на те же грабли**: `src/Command/SeoCityHubCommand.php:348-366` делает `hasAny()`/`findActive()` **один раз** (`$hasHistory`, `$prevActive`) и тут же вручную строит объект baseline-ревизии инлайн — отдельного метода со внутренним повторным `hasAny()`-гейтом там нет. Это наблюдение по чтению кода, не по прогону — пункт чеклиста ниже всё равно оставлен, стоит перепроверить прогоном на бренде/городе без истории.

## Что сделать

- [ ] воспроизвести в dry-run на бренде, для которого `brand_content_revision` пуст (SELECT ещё раз проверить перед прогоном, что кандидат не тронут `BackfillContentRevisionsCommand`); прогнать `app:brand:generate-content` без `--dry-run` на нём и посмотреть, сколько строк реально появилось в `brand_content_revision` и что лежит в поле `description` у самой ранней (`id` меньше) из них
- [ ] сверить: у самой ранней ревизии `description` должен быть СТАРЫЙ (pre-генерация) текст; если там уже новый — баг подтверждён
- [ ] починить один из вариантов: (а) `flush()` между `ensureBaseline()` и `record()` в командах, (б) убрать повторный внутренний вызов `ensureBaseline()` из `record()` (он и так вызывается снаружи в обоих путях перед перезаписью), (в) кеш «baseline уже создан в этом процессе» внутри `BrandContentVersioner` (например, `array` идентификаторов брендов за текущий запрос) — выбор зависит от того, что дешевле не сломать батч-семантику "`em->clear()` между брендами" (комментарий в `BrandContentVersioner.php:21`)
- [ ] после фикса убедиться, что городская ветка (`SeoCityHubCommand`) действительно не имеет отдельного внутреннего "record" с повторным `hasAny()` — то есть что наблюдение из «Где смотреть» верно и для неё правки не нужны (или нужны — если найдётся такой путь)
- [ ] тест/ручная проверка отката: взять бренд с baseline (после фикса), запустить рефлоу до `verdict=loss`, вызвать откат, убедиться что `brand.description` после отката байт-в-байт совпадает с текстом, который был в БД до самой первой генерации в этой цепочке

## Заметки

Серьёзность средняя, не критичная: физического удаления данных нет (ревизии append-only, правило CLAUDE.md соблюдено), проблема — в том, ЧТО именно записано в снимке "до", а не в потере записи. Проявляется только на брендах, где `BrandContentRevision` пока пуст (первая генерация контента для бренда) — в проде таких большинство уже закрыл `BackfillContentRevisionsCommand`, поэтому баг годами не был замечен; свежая партия брендов (новые лиды, geo-хабы) — как раз тот случай, когда ветка «истории нет» снова начинает срабатывать часто.

Найдено попутно при подключении гео-хабов (`CityHubRevision`, `app:seo:evaluate-experiments`) к тому же closed-loop механизму — не в рамках этой задачи, чинить в отдельной ветке/PR, не смешивать с текущей работой над `SeoCityHubCommand`/`EvaluateExperimentsCommand`.
