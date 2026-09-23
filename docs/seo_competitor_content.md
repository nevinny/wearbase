# SEO: разведка конкурентов в выдаче → gap-контекст для существующих генераторов

Идея (2026-09-23): по популярным SEO-фразам смотреть реальную выдачу, находить статьи
конкурентов, вытаскивать из них темы/структуру, которых нам не хватает, и писать
статью лучше — «встать рядом или заменить». Skyscraper Technique, адаптированный
под каталог.

## Почему это НЕ новый генератор

Прежде чем проектировать, разобрали существующий SEO-конвейер (`docs/seo_boost.md`,
код команд) — почти всё уже есть и хорошо оттестировано:

| Формат запроса | Уже покрыто | Как |
|---|---|---|
| `geo_category` («топ брендов одежды спб») | `app:seo:listicle --city=...` | рейтинг-топ по стилю+городу, grounded |
| `replace_comparison`, куратор-якорь (ушедший иностранный бренд) | `app:seo:replace-listicle` | курированный список `config/seo/replacement_anchors.yaml` |
| нишевый обзор без рейтинга (длинный хвост, мало брендов) | `app:seo:guide` | нейтральный обзор ниши/города |
| гео/стиль/аудитория — хаб-страницы своего сайта | `app:seo:city-hub` / `app:seo:style-hub` / `app:seo:audience-hub` | внутренние пилларные страницы |

Все они grounded **строго в собственных фактах бренда** (`brand.description` +
`BrandRagService::retrieve()`), с одинаковой анатомией (TOC, фактлисты
`BrandFactSheet`, in-text ссылки+UTM, JSON-LD, `LlmService::proofread`,
quality-gate: `MIN_BODY_WORDS`, лид-блок «## Коротко», factual-density,
intent-coverage). Дублировать это под «конкурентный» генератор — противоречит
Simplicity First и создаёт вторую копию quality-gate на поддержку.

**Значит новый узел — разведка + роутер, не генератор:** найти по фразе живую
выдачу, скачать статьи конкурентов, вытащить темы/структуру (НЕ факты — копировать
чужие факты нельзя, см. «Юридический риск» ниже), и передать это как
дополнительный контекст в уже существующую команду через новый опциональный флаг.

## Источник фраз

Объединяем два источника (пересечение = приоритет):

- **Wordstat** — `BrandKeyword` (`monthly_shows`). ⚠️ [Wordstat API-ключ невалиден
  (401) с 2026-08-20](../../.config-memory-ref) — `brand_keyword` не обновляется;
  пайплайн работает на протухающих данных, пока ключ не заменят.
- **Внутренний спрос** — `gsc_query_stats` / `yandex_query_stats`, та же SQL-логика,
  что уже в `SeoGapReportCommand::fetchGscRows`/`fetchYandexRows` (показы + позиция +
  `our_url` через `gsc_query_page`/`yandex_query_page`). Переиспользуем эту логику
  напрямую (метод/сервис, вынесенный из команды), а НЕ таблицу `seo_gap_snapshot` —
  та хранит только агрегаты (count по `source+band+intent_group`), без самих фраз и URL.

## Гейт по интенту (обязательно, до SERP-запроса)

`AioQueryClassifier` + группировка, ИДЕНТИЧНАЯ `SeoGapReportCommand::classifyGroup()`
(`brand_entity` / `replace_comparison` / `geo_category` / `navigation` / `other`).

- **`brand_entity`, `navigation` → исключить.** Спрос ~100% навигационный
  (`docs/seo_yandex_google_research.md`): выдача — сайт бренда, WB/Ozon/Lamoda,
  соцсети. Skyscraper здесь не работает — писать «статью лучше» не про что.
- **`geo_category`, `replace_comparison`, `other` → в цикл.** Решение по MVP-scope
  (2026-09-23): включаем **все три сразу**, включая `other` — риск в том, что часть
  `other`-фраз не ляжет ни на один существующий формат (см. ниже), это принято.

## Классификация типа страницы в выдаче

`UrlFilter` исключает только self-домен и job-агрегаторы — маркетплейсы и соцсети
**намеренно не исключены** (у них бывают реальные материалы бренда). Для этой задачи
нужна отдельная эвристика типа страницы: `article` / `marketplace` / `social` /
`official_brand_site` / `other`. Только `article` идёт в скрейп+gap-анализ (не
улучшать карточку Ozon и не анализировать структуру Instagram-профиля).

Эвристика (детерминированная, без LLM):
- домен ∈ известным маркетплейсам (wildberries.ru, ozon.ru, lamoda.ru, …) → `marketplace`;
- домен ∈ известным соцсетям (vk.com, instagram.com, t.me, …) → `social`;
- домен совпадает с `brand.site`/`brand_contact` целевого/похожего бренда в БД → `official_brand_site`;
- иначе → `article` (кандидат на скрейп).

## SERP

`YandexSearchClient::search($query, $limit)` — уже реализован, платный API
Yandex Cloud (~0.49₽/запрос, дневной потолок через `YandexSearchMeter`, fail-closed).
Порядок элементов ответа = позиция в выдаче (индекс+1, отдельного поля позиции нет).
Дроп-ин замена/дополнение `SearxClient`.

## Скрейп

`WebScraperService::fetchCleanTextWithStatus()` — уже даёт: trafilatura с fallback
на DomCrawler, лимит текста `MAX_TEXT_CHARS=12000`, дедуп строк, HTTP-статус для
диагностики. `UrlFilter::isExcluded()` — защитный барьер на входе (self+job-noise).
Кэш по времени в новой схеме реализуем сами (см. модель данных — `fetched_at` +
условие «не старше 30д» перед повторным фетчем той же `competitor_article.url`,
по аналогии с 30-дневным кэшем `WebScraperService`, упомянутым в CLAUDE.md).

## Юридический риск

Разбирать конкурента на факты и переписывать их своими словами — плагиат/
duplicate-content риск, а факты о ЧУЖОМ бренде нам всё равно грузить некуда (все
генераторы grounded в фактах ЦЕЛЕВОГО бренда, не конкурента). Поэтому gap-анализ
извлекает **темы/подзаголовки/структуру** («конкурент раскрывает: цены по сегментам,
таблицу размеров, ответ на "где купить"»), а не пересказ фактов. Дальше этот список
тем идёт в промпт существующей команды как обычная инструкция «раскрыть» — контент
она всё равно берёт из своих (наших) фактов. Ничего из текста конкурента не попадает
в готовую статью дословно (гарантия — п. «Anti-duplicate» ниже).

## Модель данных (2 сущности)

Одна проверка фразы сравнивает НЕСКОЛЬКО конкурентных URL против нашей страницы —
это принадлежит фразе, не одной статье. Отдельно кэш содержимого по URL (одна и та
же статья может встретиться под несколько фраз — не перескрейпить).

**`CompetitorArticle`** (таблица `competitor_article`) — кэш контента по URL:
- `id`, `url` (unique), `domain`
- `page_type` (article|marketplace|social|official_brand_site|other)
- `title`, `content` (longtext, из `WebScraperService`), `word_count`
- `http_status` (nullable int), `fetched_at`
- `Created` trait (created_at/updated_at)

**`SeoCompetitorScan`** (таблица `seo_competitor_scan`) — одна строка на проверку фразы:
- `id`, `keyword`, `demand_source` (wordstat|gsc|yandex|both — откуда взята фраза)
- `intent_group` (geo_category|replace_comparison|other)
- `our_url` (nullable) — резолвится той же логикой, что `SeoGapReportCommand`
- `serp_results` (json) — `[{url, position, page_type, competitor_article_id}]`
- `gap_summary` (text, nullable) — LLM-извлечение тем/структуры (НЕ факты конкурента)
- `priority_score` (float, nullable) — эвристика: показы × (нет `our_url` ИЛИ band=gap) × кол-во нераскрытых тем
- `status` (pending|scanned|analyzed|routed|error) — обычная строка, без Status/soft-delete
  (внутренняя рабочая таблица конвейера, не пользовательская сущность — правило
  «только soft-delete» из CLAUDE.md про пользовательские данные сюда не применяется,
  но и `DELETE` руками не делаем — таблица без ручного удаления по построению)
- `checked_at`, created/updated

Обе — обычные `CREATE TABLE IF NOT EXISTS` миграции (`doctrine:migrations:diff`).

## Anti-duplicate

Перед сохранением сгенерированной (существующей командой) статьи прогонять
`NearDuplicateDetector::similarity($ourBody, $competitorArticle->content)` против
**каждой** `competitor_article` из `serp_results` данной фразы. `DROP_THRESHOLD=0.85`
как в доктрине пакета — если сходство выше, это уже не «gap-контекст», а пересказ,
блокировать сохранение (в дополнение к штатному quality-gate команды, который
сравнивает наш текст только с НАШИМ корпусом).

## Роутинг (что делает `scan`, а не отдельный «rewrite»)

`geo_category` → рекомендация: `app:seo:listicle <brand> <style> --city=<город> --gap-context=<scan_id>`.
`replace_comparison`, есть готовый якорь в `replacement_anchors.yaml` → `app:seo:replace-listicle --anchor=<slug> --gap-context=<scan_id>`.
`replace_comparison`, якоря нет (иностранный бренд не в списке ИЛИ сравнение двух
российских брендов) → **v1: только флаг в отчёте** «нет готового пути, нужна
курация» (не создавать текст с нуля вне проверенных путей — MIN_REPLACEMENTS и
юридический denylist в `ReplaceListicleCommand` специально узкие).
`other` → пробуем сматчить на `app:seo:guide <style> [--city]` или
`app:seo:audience-hub`; если фраза не ложится ни на один существующий
niche/audience/city срез (свободный вопрос вне каталога брендов) → **v1: только
отчёт, без генерации** (это тот самый принятый риск MVP-решения "сразу включить
other" — часть таких строк не получит действия до отдельного генератора).

## Интеграция в существующие команды — один новый опциональный флаг

`GenerateListicleCommand`, `SeoGuideCommand`, `ReplaceListicleCommand` получают
`--gap-context=<id>` (id строки `seo_competitor_scan`): подгружает `gap_summary` и
добавляет в промпт как ОБЫЧНУЮ инструкцию по покрытию (аналогично тому, как уже
передаётся `$keywords`/`$fixHint` в `LlmService::generateListicle`/
`generateReplacementListicle`) — «покрыть также: {темы}, т.к. их раскрывают
конкуренты в топе выдачи». Это единственное изменение существующего кода — никаких
новых prompt-веток/quality-gate, вся остальная сборка (TOC, фактлисты, JSON-LD,
proofread, near-dup) остаётся как есть.

## Новая команда

`app:seo:competitor-scan [--source=wordstat|gsc|yandex|both] [--intent=geo_category,replace_comparison,other] [--limit=N] [--serp-limit=5] [--notify] [--dry-run]`

Флоу: резолв фраз → фильтр интента → по фразе: `YandexSearchClient::search()` →
классификация типа страницы → топ-N `article`-результатов → кэш/фетч через
`CompetitorArticle` (пропуск, если `fetched_at` свежее 30д) → LLM-извлечение тем
(новый метод `LlmService::extractCompetitorTopics` или похожий — только темы,
не факты) → `our_url` резолв → `priority_score` → сохранение `SeoCompetitorScan` →
консольная таблица + рекомендованная команда для запуска + `--notify` в TG тем же
`AdminNotifier`, что остальные SEO-команды.

## Открытые вопросы для реализации (backend-developer)

1. Список маркетплейсов/соцсетей для `page_type` — свериться с `UrlFilter::JOB_NOISE`
   по формату, но это отдельный список (маркетплейсы там нарочно не исключены).
2. Метод извлечения тем в `LlmService` — сигнатура и промпт с явным запретом
   пересказывать факты конкурента, только структура/темы.
3. `priority_score` — точная формула (шоуы × множители) — можно калибровать после
   первого прогона на реальных данных, не блокирует MVP.
4. Wordstat 401 (см. `wordstat-api-key-invalid` в памяти) — `demand_source=wordstat`
   даст только протухшие данные, пока ключ не заменят; `gsc`/`yandex` не затронуты.
