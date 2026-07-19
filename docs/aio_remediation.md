# AIO-ремедиация: closed-loop под AI Overviews

> Спрос-ориентированный цикл: **GSC-запросы → радар AIO-утечки → правка страницы → замер → откат**.
> Построено 2026-07-19 по [docs/drmax_seo_2026_digest.md](drmax_seo_2026_digest.md) §5 (regex-свип), §1/§3 (extractable/information gain), §7 (spam-шаблоны) и [docs/seo_sitewide_backlog.md](seo_sitewide_backlog.md) HIGH#2. Всё крутится **на Mac** (там GSC-данные, `brand.*`, ревизии); результат уезжает на прод обычным пушем контента.

## Зачем
GSC-запросы вида «{бренд} чей бренд» дают показы, но клик забирает AI Overview (clicks≈0). Цель — найти такие запросы, отдать странице то, что делает её цитируемой в нейроответе, и **измерить** эффект, откатывая проигравшее. Это radar приоритетов, а не «улучшать SEO вообще».

## Поток и расписание (крон, Mac, время МСК)
| Шаг | Команда | Крон | Пишет |
|---|---|---|---|
| Сбор запросов | `app:gsc:sync` | 08:00 | `gsc_page_stats` (page) + **`gsc_query_stats`** (query — второй pull `dimensions=[query,date]`) |
| Ремедиация | `app:seo:aio-remediate --apply --limit=10 --notify` | **пн 08:40** | `brand.description`+revision (thin) / `brand_faq` (rich) / аудит `aio_remediation` |
| Замер + откат | `app:seo:evaluate-experiments` | 10:00 ежедн. | вердикт win/loss в `brand_content_revision`, rollback при loss |
| Отчёт | секция «🔎 AIO-утечка» в `app:report:daily` | 09:10 | — (TG) |
| Ручной свип | `app:seo:aio-queries --limit=N` | — | — (только чтение/консоль) |

## Классификатор — `App\Service\Seo\AioQueryClassifier`
Единый источник правды (используют и свип, и дайджест, и ремедиация). Классифицирует запрос по формату + ожидаемый trigger rate AIO:
- `freshness` 100% (эксперим., §5) · `question` 57.9% · `howto` ~58% · `comparison` 26.2% · `brand_entity` ≈57.9% (RU-оценка) · `best_top`/`commercial` baseline ~21% · `other`.
- **RU-адаптация:** мастер-regex дайджеста EN-центричный (якорь `^`). Добавлена группа **`brand_entity`** под доминанту каталога «{бренд} **чей бренд**» (маркер в конце: «чей бренд / что за бренд / какой страны / кто производитель»), `question` ловит и неякорные «что это / это что». `AIO_LIKELY` = группы с высокой вероятностью AIO (всё кроме other/commercial/best_top).

## Гибрид ремедиации — `app:seo:aio-remediate`
Detect (brand_entity-утечка: показы≥`--min-impr`, клики=0) → map на **published** бренд (`BrandRepository::findOneActiveByTitle`, не foreign; Mac-статус верен после publish-sync, см. ниже) → развилка по длине описания (порог `GenerateBrandContentCommand::MIN_REAL_DESCRIPTION_CHARS` = 400):

**THIN (описание < 400):** переиспользует `GenerateBrandContentCommand --id --grounded-only` — RAG-генерация описания, `BrandContentVersioner::record()` стартует measured-эксперимент. Пишет `brand.description`+meta + `brand_content_revision`. **Измеряется и откатывается** (см. замер). `kind='description'`.
*Обоснование DrMax: тонкая/пустая страница = реальный information gain (§1599); генерить можно.*

**RICH (описание ≥ 400):** DrMax запрещает переписывать ранжирующееся (§1210/1222/1599) → **не трогаем тело**, а закрываем дефицит видимым блоком:
- **Skip «уже покрыто»**, если на карточке рендерится детерминированный блок «Что за бренд X?» (условие из `show.html.twig`: `brand.city` ИЛИ `brand.foundingYear` ИЛИ видимый `category`-атрибут) **или** уже есть entity-FAQ (`BrandFaqRepository::hasBrandEntityQuestion`).
- Иначе — **grounded gap-FAQ**: факты через `BrandRagService` + `LlmService::generateBrandFaq` (вопрос-затравка «что за бренд X / X чей бренд», ответ answer-first 40–60 слов), гейт `ContentValidator::isRefusal`, нет фактов → skip. На `--apply`: append `BrandFaq(source=llm)` + бамп `BrandRagPipeline::contentChangedAt` (свежесть/пуш) — **без `FAQ_DONE`** (иначе богатый Wordstat-FAQ-батч `app:brand:faq` больше не подхватит бренд). `kind='faq_gap'`.
*Обоснование DrMax: layered видимый блок (аккордеон FAQ), не схема — JSON-LD не повышает AIO-цитирование на видимых страницах (§1457).*

Опции: `--dry-run` (дефолт — превью без записи), `--apply`, `--limit`, `--min-impr`, `--notify` (текстовая сводка в TG, без кнопок).

## Замер и откат — `app:seo:evaluate-experiments`
Берёт ревизии с `measureAfter ≤ now` (окно 28/21/14 дн по попытке), снимает GSC+Яндекс «после», сравнивает с baseline «до» (пороги `DELTA_ABS/REL`, `MIN_SAMPLE`): вердикт **win / loss / neutral / not_indexed**. **loss → `BrandContentVersioner::rollback()`** к прошлой рабочей ревизии (append-only). Пограничные — продлевает окно (`RE_MEASURE_DAYS`).

⚠️ **Честная граница:** замер покрывает только **THIN**-ветку (revision = description/meta). **RICH (gap-FAQ) НЕ измеряется и не откатывается** revision-loop'ом — компенсируется тем, что добавка additive + grounded + gated (низкий риск). Полноценный замер FAQ — будущая доработка `evaluate-experiments`.

## Данные
- **`gsc_query_stats`** (миграция `Version20260719_gsc_query_stats`): query, day, impressions, clicks, ctr, position; `UNIQUE(query,day)`.
- **`aio_remediation`** (миграция `Version20260719_aio_remediation`, сущность `AioRemediation`): аудит применённого — brand, query, kind (`description`/`faq`), proposed_*, status, applied_at.

## Кросс-хостовые грабли (важно)
Mac и прод — **разные MySQL** (см. [[publish-truth-is-on-prod]]):
- **«Опубликован» = состояние прода.** Mac `status`/`published_at` лагает; синхронизирует `publish-sync` прод→Mac (соседний контур). Мэппинг ремедиации по Mac-статусу верен **после синка**; runtime-проверку прода в команду НЕ городим.
- **TG-вебхук на проде**, а кандидаты/авторинг на Mac → **кнопки apply в TG работать не могут** (прод не видит строк Mac-БД). Поэтому apply — **автоматический**, а safety-сетка не ручное подтверждение, а **measured-rollback** (для thin) + grounded-гейт (для rich). Это осознанный выбор closed-loop вместо кнопки.
- `prod brand.id ≠ dev brand.id` — кросс-БД ключ = **slug**. Ремедиация генерит через `--id` (Mac-локальный) — ок, кросс-БД id не используется.

## Ручной прогон / пример
```bash
# свип запросов (только чтение)
php -d memory_limit=512M bin/console app:seo:aio-queries --limit=30
# ремедиация — превью без записи (дефолт dry-run)
php -d memory_limit=512M bin/console app:seo:aio-remediate --limit=5
# применить
php -d memory_limit=512M bin/console app:seo:aio-remediate --apply --limit=10 --notify
```
Наблюдение на 2026-07-19: из 11 «чей бренд»-утечек 6 смэтчены — все **rich + уже покрыты** блоком «Что за бренд X?» → действий 0 (консервативно). Тонкие/непокрытые бренды со спросом → пойдут правки.

## Файлы
`src/Service/Seo/AioQueryClassifier.php` · `src/Command/{SyncGscCommand,SeoAioQueriesCommand,AioRemediateCommand,DailyReportCommand,GenerateBrandContentCommand,EvaluateExperimentsCommand}.php` · `src/Service/BrandContentVersioner.php` · `src/Entity/{AioRemediation,BrandContentRevision,BrandFaq}.php` · миграции `Version20260719_*`.
