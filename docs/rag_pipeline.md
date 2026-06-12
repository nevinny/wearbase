# RAG-конвейер генерации контента брендов

> Конвейер собирает контент брендов (описание, meta, FAQ, контакты, атрибуты) **из реальных
> фактов**, добытых из сети, а не «из головы» модели. Цель — заземлять (ground) генерацию на
> скрейпленном корпусе бренда, чтобы не плодить галлюцинации и SEO-воду.
> Справочник самих команд (cron-сводка, как часто/где запускать) — [commands.md](commands.md);
> здесь — устройство пайплайна, статус-машина, gate качества и подводные камни.

Весь AI-стек живёт на LLM-сервере **192.168.2.43**: ollama (генерация + эмбеддинги), Qdrant `:6333`
(векторы), SearXNG `:8080` (поиск), trafilatura (извлечение текста). Env — в `.env.local`
(`LOCAL_LLM_URL/MODEL`, `LOCAL_EMBED_URL/MODEL`, `QDRANT_URL/API_KEY/COLLECTION`, `SEARXNG_URL`,
`TRAFILATURA_BIN`, `WORDSTAT_API_KEY`, опц. `YANDEX_SEARCH_API_KEY/FOLDER_ID`,
`SCRAPE_EXCLUDED_DOMAINS`).

## Содержание

1. [Обзор и схема потока](#1-обзор-и-схема-потока)
2. [Статус-машина BrandRagPipeline](#2-статус-машина-brandragpipeline)
3. [Этапы по порядку](#3-этапы-по-порядку)
4. [Модель данных](#4-модель-данных)
5. [Gate качества (BrandRagService)](#5-gate-качества-brandragservice)
6. [Контакты (enrich-contacts)](#6-контакты-enrich-contacts)
7. [Оркестрация (rag:daemon)](#7-оркестрация-ragdaemon)
8. [Подводные камни (gotchas)](#8-подводные-камни-gotchas)
9. [Ручное вмешательство](#9-ручное-вмешательство)

---

## 1. Обзор и схема потока

Магистральный поток — четыре стадии, каждая своей командой и со своим статусом в
`brand_rag_pipeline`. Вокруг них — побочные стадии обогащения (crawl / keywords / faq / extract /
wb / push), которые читают тот же корпус и пишут свои поля статуса.

```
              ┌─────────────────────────── магистраль ───────────────────────────┐
brand (active/new)
   │
   ▼  app:brand:discover            наполняет brand_source_url (URL-очередь)
discovered_at, has_own_site (provisional)
   │
   │  app:brand:crawl               own_site → внутренние own_page/product_sample в очередь
   │                                (между discover и fetch; нет own_site → skipped)
   ▼  app:brand:fetch               дренит очередь → brand_source_document (скрейп текста)
status=scraped, source_count, has_own_site (confirm/demote)
   │
   ▼  app:brand:embed               чанки → эмбеддинги → Qdrant (brand_chunks)
status=embedded
   │
   ▼  app:brand:generate-content    RAG-retrieve → gate → description+meta (+anons)
status=done | deferred | review
   │
   └─────────────── побочные обогащения (читают корпус / Qdrant / ключевики) ──────────────┐
        app:brand:keywords   Wordstat topRequests → brand_keyword       (keywordsStatus)    │
        app:brand:faq        FAQ из ключевиков + фактов → brand_faq       (faqStatus)        │
        app:brand:extract    атрибуты из product_sample-краула           (attributesStatus) │
        app:brand:wb-enrich  ингест карточек Wildberries                 (wbStatus)          │
        app:brand:enrich-contacts  контакты из корпуса → email/phone/links/stores           │
                                                                                            ▼
                                                          app:brand:push → прод (агент-API)
                                                          pushedAt; ре-доставка по contentChangedAt
```

Магистраль и побочные стадии **resumable**: статус-машина переживает перезапуски, каждая стадия
добирает то, что готово. Оркеструет всё `app:rag:daemon` (см. §7).

---

## 2. Статус-машина BrandRagPipeline

Сущность `App\Entity\BrandRagPipeline` (таблица `brand_rag_pipeline`, 1:1 с `Brand`,
`onDelete: CASCADE`). Магистральный `status` + отдельные per-stage поля для побочных обогащений
(они **не** меняют `status`, а ведут собственный статус-флаг + timestamp).

### Магистральный `status` (VARCHAR 20)

| Константа | Значение | Кто ставит | Смысл |
|---|---|---|---|
| `STATUS_PENDING` | `pending` | (default) / requeue | Новая строка, ещё не скрейплена |
| `STATUS_SCRAPED` | `scraped` | `fetch` (finalize, когда очередь дренирована) | Корпус собран (даже 0 документов) |
| `STATUS_EMBEDDED` | `embedded` | `embed` | Чанки залиты в Qdrant |
| `STATUS_GENERATED` | `generated` | — (зарезервирован; код пишет сразу `done`) | — |
| `STATUS_DONE` | `done` | `generate-content` | Description + meta сгенерированы |
| `STATUS_SCRAPE_FAILED` | `scrape_failed` | — | (зарезервирован) |
| `STATUS_EMBED_FAILED` | `embed_failed` | `embed` (ошибка) | Сбой эмбеддинга, `embedAttempts++` |
| `STATUS_GENERATE_FAILED` | `generate_failed` | — | (зарезервирован) |
| `STATUS_DEFERRED` | `deferred` | `generate-content --grounded-only` | Корпус не прошёл gate → ждём дозревания |
| `STATUS_REVIEW` | `review` | `generate-content` (refusal) | Модель отказала (факты не о бренде) → ручная верификация, **не публикуем** |

> `STATUS_GENERATED`, `STATUS_SCRAPE_FAILED`, `STATUS_GENERATE_FAILED` объявлены, но в текущем
> коде магистрали не выставляются (генерация пишет сразу `done`; сбои скрейпа живут на уровне
> `BrandSourceUrl.status=failed`, а не пайплайна).

```
                     discover                fetch                  embed
brand ──► [нет строки] ──discovered_at──► pending ──drain──► scraped ──► embedded
                                                                            │
                                            generate-content (--grounded-only)
                                                                            ▼
                              ┌───────────────────────────────────────────────────────────┐
                              │ gate пройден           gate НЕ пройден      модель отказала │
                              ▼                         ▼                    ▼               │
                            done                     deferred              review           │
                              │                         │ (новые URL → fetch вернёт          │
                       push на прод                     │  в scraped → embed → сюда)         │
                                                        └────────────────────────────────────┘

embed-сбой → embed_failed (embedAttempts++); retry на следующем прогоне
review → admin /admin/rag/review → requeue (сброс в pending) | hide (inactive)
```

### Per-stage статусы (не меняют магистральный `status`)

| Поле | Константы | Команда | `null` = |
|---|---|---|---|
| `keywordsStatus` | `KW_FOUND` `found` / `KW_NOT_FOUND` `not_found` | `keywords` | не опрашивали Wordstat |
| `wbStatus` | `done` / `no_products` / `error` (строки, без констант) | `wb-enrich` | не обрабатывали |
| `crawlStatus` | `CRAWL_DONE` / `CRAWL_SKIPPED` (нет own_site) / `CRAWL_FAILED` | `crawl` | не краулили |
| `attributesStatus` | `ATTR_DONE` / `ATTR_SKIPPED` / `ATTR_FAILED` | `extract` | не извлекали |
| `faqStatus` | `FAQ_DONE` / `FAQ_SKIPPED` (нет ключевиков) / `FAQ_FAILED` | `faq` | не генерили |

Соответствующие timestamp-поля: `discoveredAt`, `scrapedAt`, `embeddedAt`, `generatedAt`,
`keywordsCheckedAt`, `wbCheckedAt`, `crawledAt`, `extractedAt`. Счётчики попыток: `scrapeAttempts`,
`embedAttempts`, `generateAttempts`, `pushAttempts`. Аудит grounding: `sourceCount` (сколько
документов), `grounded` (использовался ли RAG-контекст), `topRetrievalScore` (топовый cosine).

### Доставка на прод

- `pushedAt` — когда бренд доставлен на прод (`null` = не доставлен).
- `contentChangedAt` — когда доставляемые данные менялись (обогащение **после** пуша).
  **Триггер ре-доставки**: push берёт бренд, если `contentChangedAt > pushedAt`. Ставится при
  генерации (`markGenerated`) и при обогащении контактами / атрибутами / FAQ
  (`BrandRagPipelineRepository::markContentChanged`).
- `pushError`, `pushAttempts` (push прекращается после 3 попыток).

### Предикат готовности к публикации

`BrandRagPipeline::isPublishReady()` (используется агент-пушем):
`description != '' && metaTitle != '' && metaDescription != '' && status === done`
`&& faqStatus ∈ {done, skipped} && keywordsStatus ∈ {found, not_found}`.
То есть FAQ может быть законно пропущен (нет ключевиков), а `keywords not_found` (нишевый бренд) не
блокирует — но **опросить** Wordstat надо.

---

## 3. Этапы по порядку

### 3.0 `app:brand:discover` — discovery URL-кандидатов

`DiscoverBrandSourcesCommand`. Через `BrandSourceFinder::discoverTiered()` (SearXNG + опц. Yandex
Search API + DB-ссылки) находит URL-кандидаты бренда **без скачивания страниц** и кладёт в очередь
`brand_source_url` (дедуп по `url_hash`, cap по `source_type`).

- **Вход**: активные бренды (`status ∈ {active, new}`) без `discovered_at`.
- **Выход**: строки `brand_source_url` (status=`pending`); `pipeline.has_own_site` (provisional),
  `discovered_at`. **Не трогает `pipeline.status`.**
- **Параметры**: `limit` (брендов/запуск, деф. 50), `--id`, `--max` (кандидатов/бренд, деф. 50),
  `--shard`/`--total` (шардинг для параллельных процессов), `--dry-run`.
- **CAPS** (макс. URL данного типа в очереди у бренда, суммарно по запускам, `DiscoverBrandSourcesCommand::CAPS`):
  `own_site=2`, `marketplace=5`, `catalog=6`, `article_review=5`, `social=6`, `mention=6`.
- **Фейлы**: SearXNG лежит (движки suspended/CAPTCHA) → бренд **не** помечается discovered (иначе
  сгорит с пустыми тирами); **circuit breaker** — 3 бренда подряд с лежащим SearXNG → стоп всего
  прогона.

### 3.1 `app:brand:crawl` — разворот own_site (между discover и fetch)

`CrawlBrandSiteCommand` (`app:brand:crawl`). Берёт own_site и через
`WebScraperService::discoverSitePages()` (sitemap.xml + ссылки с главной) добавляет внутренние
страницы в очередь как `own_page` / `product_sample` (status=`pending`).

- **Выход**: `brand_source_url` (типы `own_page`/`product_sample`); `crawlStatus` =
  `done` / `skipped` (нет own_site — краулить нечего) / `failed`, `crawledAt`.
- `product_sample`/`own_page` фетчатся с `keepTables=true` (сохраняем размерные сетки/таблицы для
  стадии `extract`).

### 3.2 `app:brand:fetch` — дренаж очереди → документы

`FetchBrandSourcesCommand`. Атомарно клеймит пачку `pending`-URL своего шарда (`claimPending`:
порядок `tier ASC, relevance_score DESC`), скачивает чистый текст
(`WebScraperService::fetchCleanText` — trafilatura, fallback HTTP+DomCrawler), сохраняет в
`brand_source_document`.

- **Вход**: `brand_source_url` status=`pending`.
- **Выход**: `brand_source_document` (clean_text, content_hash, char_count, carry-forward
  source_type + relevance). URL → `fetched` (успех) / `failed` (`attempts++`).
- **Финализация**: когда у бренда не осталось `pending`/`claimed` URL → `pipeline.status=scraped`
  + `scrapedAt` + `sourceCount` (`countByBrand`) + `has_own_site` (Фаза B: own_site-документ
  реально скачан с непустым текстом → `true`, иначе `false`).
- **Параметры**: `--shard`/`--total`, `--batch` (URL/claim, деф. 50), `--max-urls` (ломоть на
  запуск, 0 = дренить до пустой очереди — для демона ставится `250`), `--dry-run`.
- **Кеш/дедуп**: свежий (≤30 дней) документ по URL не перекачивается; дубль по `content_hash`
  (тот же или другой URL бренда) не сохраняется; текст `< 200` симв. = мусор, документа нет (но URL
  помечен `fetched`).
- **Self-heal**: `reclaimStale` на старте (claimed дольше 30 мин → обратно в `pending`).
- **Фейлы**: сетевой/HTTP-сбой → URL `failed`, `attempts++`, `lastError`.

### 3.3 `app:brand:embed` — чанки → Qdrant

`EmbedBrandSourcesCommand`. Чанкует clean_text (`TextChunker`), считает эмбеддинги (ollama
`/api/embed`, 1024-dim), заливает векторы в Qdrant-коллекцию `brand_chunks`.

- **Вход**: бренды в статусе `scraped` (`BrandRepository::findForEmbed`); по умолчанию только
  unembedded-документы, `--force` — перезалить (удалить векторы бренда).
- **Выход**: точки в Qdrant; `document.embedded=true`; `pipeline.status=embedded` + `embeddedAt`.
- **Параметры**: `limit`, `--id`, `--force`, `--shard`/`--total`, `--dry-run`.
- **NaN-skip**: эмбеддинг идёт **по-чанково** — изредка embed-модель выдаёт NaN на мусорном тексте
  и роняет весь батч (ollama не сериализует NaN в JSON); сбойный чанк пропускается, остальные
  сохраняются.
- **0 чанков** (нет текста): статус всё равно продвигается до `embedded` — генерация уйдёт в legacy
  fallback (gate не пройдёт).
- **Фейлы**: Qdrant недоступен/несовместим (другая размерность) → команда падает на старте; сбой
  бренда → `embed_failed`, `embedAttempts++`.

### 3.4 `app:brand:generate-content` — генерация description + meta

`GenerateBrandContentCommand`. Достаёт факты из Qdrant через `BrandRagService::retrieve()` (см.
§5), генерирует description (`LlmService::generateBrandDescription`) и meta
(`generateMetaFromExistingDescription`, до 3 ретраев на валидации `ContentValidator`), плюс анонс.

- **Вход**: бренды без описания (`findWithoutDescription`) или `--meta-only` (есть описание, нет
  meta).
- **Выход**: `brand.description`, `metaTitle/metaDescription/metaKeywords`, `anons`;
  `pipeline.status=done` + `generatedAt` + `grounded` + `topRetrievalScore` + `contentChangedAt`.
- **Ключевики**: топ-фразы Wordstat (`brand_keyword`, ранжированы origin>related по частоте)
  вплетаются в описание и **перебивают** LLM-вариант в `meta.keywords`.
- **`--grounded-only`** (режим демона): если контекст `null` (gate не пройден) → бренд `deferred`,
  description **не** перезаписывается (legacy-вода зацементировалась бы); когда корпус дорастёт,
  fetch вернёт бренд в `scraped` → embed → сюда.
- **Отказ модели** (`ContentValidator::isRefusal` — факты про другую сущность / недостаточны) →
  `pipeline.status=review`, `lastError`, **не ретраим** (корпус не тот). Ручная верификация в
  `/admin/rag/review`.
- **QA-гейт текста** (`ArticleQaService` → `tools/article-qa-toolkit`, python stdlib): после
  валидации description прогоняется через эвристики AI-почерка/переспама/повторов/воды
  (методология SEO Guide 4.9, см. `_seo/`). Порог: **SpamBrain ≥7 И Human-likeness ≥8 И
  overall ≥75** (ось Reader Value не применяем — откалибрована под статьи 1200+ слов). FAIL →
  не сохраняем, meta не генерим (экономим LLM), бренд подберётся следующим прогоном. Сбой
  инфраструктуры (нет python3 и т.п.) — fail-open с warning в лог, батч не останавливается.
  Выключатель: env `ARTICLE_QA_ENABLED=0`. Учитывается `--skip-validate`.
- **`has_own_site=false`** (корпус только из упоминаний/маркетплейсов) → генерируем, но логируем
  пониженную уверенность grounding. `null` (discover не прогонялся, legacy) — не сигналим.
- **Параметры**: `limit`, `--id`, `--meta-only`, `--skip-validate`, `--grounded-only`,
  `--shard`/`--total`, `--dry-run`.

### 3.5 Побочные стадии

| Команда | `AsCommand` | Роль | Пишет |
|---|---|---|---|
| Keywords | `app:brand:keywords` | Wordstat `topRequests` → `brand_keyword` | `keywordsStatus`, `keywordsCheckedAt` |
| FAQ | `app:brand:faq` | FAQ из ключевиков + фактов → `brand_faq` (после generate) | `faqStatus`, `contentChangedAt` |
| Extract | `app:brand:extract` | Атрибуты (размеры/материалы) из product_sample-краула | `attributesStatus`, `extractedAt`, `contentChangedAt` |
| WB-enrich | `app:brand:wb-enrich` | Ингест карточек Wildberries | `wbStatus`, `wbCheckedAt` |
| Push | `app:brand:push` | Доставка готовых брендов на прод (агент-API + HMAC) | `pushedAt`, `pushAttempts`, `pushError` |

> **keywords** — отдельный долгоживущий демон (квота Wordstat 100/час, ~56 мин/цикл), **не** входит
> в дефолтный цикл `rag:daemon`. **wb-enrich** (`app:brand:wb-enrich`) — реальная стадия, но в
> `RagDaemonCommand::STAGES` её **нет** (запускается отдельно/вручную).

---

## 4. Модель данных

### `BrandSourceUrl` (`brand_source_url`) — URL-очередь

discover наполняет, fetch дренит.

| Поле | Тип / значения |
|---|---|
| `status` | `pending` / `claimed` / `fetched` / `failed` / `skipped` |
| `sourceType` | `own_site` / `own_page` / `product_sample` / `marketplace` / `catalog` / `article_review` / `social` / `mention` |
| `tier` | `TIER_OWN_SITE=1` / `TIER_CORPUS=2` / `TIER_MENTIONS=3` |
| `relevanceScore` | 0..1 (скоринг finder, carry-forward в документ) |
| `urlHash` | sha256(нормализованный url: lowercase scheme/host, rtrim `/`) |
| `attempts`, `lastError`, `discoveredAt`, `claimedAt`, `fetchedAt` | очередь/аудит |

- **Дедуп-ключ**: уникум `(brand_id, url_hash)`, **не** по `url` (VARCHAR(1024) utf8mb4 превышает
  3072-байт лимит уникального индекса InnoDB). Хэш считается из нормализованного URL.
- `own_page` / `product_sample` появляются на стадии `crawl` (разворот own_site).

### `BrandSourceDocument` (`brand_source_document`) — скрейпленные страницы

Хранятся в MySQL (а не только в Qdrant), чтобы переэмбеддить при смене модели без повторного
скрейпа, дедуплицировать и иметь SQL-аудит.

| Поле | Тип / значения |
|---|---|
| `sourceType` | **default `official_site`** (legacy) — carry-forward из очереди; `own_site`/`marketplace`/`catalog`/`article_review`/`social`/`mention` |
| `relevanceScore` | carry-forward веса источника (вес при retrieve/prioritize) |
| `cleanText` | очищенный текст; сеттер сам считает `contentHash` (sha256) + `charCount` |
| `rawText` | сырой HTML **не храним** (всегда `null`) |
| `embedded` | залиты ли чанки в Qdrant |
| `keywords` | ключевики (Phase 7) |

- **Дедуп-ключ**: уникум `(brand_id, content_hash)`.
- Константы `TYPE_OFFICIAL/SOCIAL/META` — legacy; реальная таксономия carry-forward из
  `BrandSourceUrl.sourceType`.

### Qdrant-коллекция `brand_chunks` (`VectorStoreService`)

- 1024-мерные векторы, distance **Cosine**, payload-index на `brand_id`.
- **Vector size 1024 фиксирован в коде** (`VECTOR_SIZE`); модель env-инжектируется
  (`LOCAL_EMBED_MODEL`). Комментарии кода расходятся (EmbeddingService: «bge-m3»;
  VectorStoreService: «qwen3-embedding:0.6b, bge-m3 даёт NaN в ollama 0.22») — авторитетна
  env-переменная + фиксированная размерность.
- **Point id** — детерминированный `UUIDv5(namespace, "{brandId}:{contentHash}:{chunkIndex}")`:
  повторный эмбеддинг того же чанка **перезаписывает**, а не плодит дубли.
- **Payload**: `brand_id`, `doc_id`, `chunk_index`, `source_url`, `source_type`, `relevance`,
  `text`.

### Связь таксономии source_type (тонкое место)

```
BrandSourceUrl.sourceType  ──fetch carry-forward──►  BrandSourceDocument.sourceType
   (own_site/marketplace/…)        (default official_site, legacy)
                                            │
                                       embed payload
                                            ▼
                                  Qdrant payload.source_type
                                            │
                            BrandRagService.OWN_SITE_TYPES = {own_site, official_site}
                            (оба считаются «собственным сайтом» при prioritize)
```

---

## 5. Gate качества (BrandRagService)

`BrandRagService::retrieve(Brand): {context:?string, score:?float, chunks:int}`. Если фактов мало
или релевантность низкая → `context=null` → генерация уходит в **legacy-режим** (модель пишет из
своих знаний; с `--grounded-only` бренд вместо этого уходит в `deferred`).

**Адаптивный retrieve:**
- чанков `≤ MULTI_ASPECT_MIN(8)` → один запрос (`singleQuery`, `TOP_K=6`);
- чанков `> 8` (контентный сайт) → multi-aspect: 5 граней (ассортимент / материалы / философия /
  аудитория / производство), `PER_ASPECT=3` каждая, дедуп по id (лучший score), срез `MAX_HITS=8`.

**Сам gate (на cosine-score):**

```
chunks < MIN_CHUNKS (3)            → context=null  (легаси/deferred)
   ИЛИ topScore == null
   ИЛИ topScore < MIN_SCORE (0.5)  → context=null
иначе                              → собрать контекст из топ-хитов
```

| Константа | Значение | Роль |
|---|---|---|
| `MIN_CHUNKS` | 3 | меньше чанков — **не заземляем** |
| `MIN_SCORE` | 0.5 | cosine топ-хита ниже — мусорная релевантность, **не заземляем** |
| `MAX_HITS` | 8 | итоговый максимум чанков |
| `MAX_CONTEXT_CHARS` | 6000 | потолок текста контекста (блоки набираются, пока влезают) |
| `TOP_K` | 6 | одиночный запрос |
| `RELEVANCE_FLOOR` | 0.35 | **НЕ часть gate** (см. ниже) |

> **`RELEVANCE_FLOOR` ≠ порог gate.** Решение grounded/legacy принимается **только** по
> `chunks/MIN_CHUNKS` и `topScore/MIN_SCORE` (cosine). `RELEVANCE_FLOOR` работает **позже**, в
> `prioritize()`/`assemble()`: выкидывает чанки, у которых **payload-relevance** (вес discovery,
> не cosine) `>0` и `< 0.35` (омонимы/мусор; `0`/отсутствие = не размечено → оставляем). Если бы
> фильтр опустошил набор — он **откатывается** к исходному (gate уже пройден). То есть он влияет на
> *что попадёт в контекст*, но никогда не роняет в legacy.

`prioritize()` также ставит own_site-чанки (`OWN_SITE_TYPES = {own_site, official_site}`) раньше,
сохраняя cosine-порядок внутри групп (стабильный usort PHP 8.2).

**Итоги в `brand_rag_pipeline`**: `grounded` (`context !== null`), `topRetrievalScore`.

---

## 6. Контакты (enrich-contacts)

`EnrichBrandContactsCommand` (`app:brand:enrich-contacts`). **Работает на ЛОКАЛЬНОМ
скрейп-корпусе**, не на платном Perplexity.

> ⚠️ **Perplexity удалён 2026-06-04** (бэклог п.10). Раньше команда дёргала `perplexity/sonar`
> через OpenRouter; теперь источник контактов — текст из `brand_source_document` бренда,
> извлечение через `LlmService::extractBrandContactsFromText` (локальная модель). Контракт ответа
> тот же, что был у Perplexity-пути — `applyContacts()`/нормализация не менялись.
> (CLAUDE.md в разделе «Brand contact enrichment» всё ещё описывает старый perplexity-режим —
> это устарело; авторитетен код.)

**Поток одного бренда:**
1. Собрать `cleanText` всех `brand_source_document` бренда. **Пусто → пропуск до fetch**
   (статус **не** трогаем: бренд останется в выборке и обогатится, когда конвейер принесёт текст).
2. `LlmService::extractBrandContactsFromText(brandName, scrapedText, city)` → `{confidence, email,
   phone, website, instagram, vk, telegram, youtube, stores[], notes}`. В логе:
   `источник: локальный скрейп (N стр.)` — N = число документов корпуса.
3. Применить (не перезаписывая существующее): `email`/`phone` (валидация `ContactVerifier`),
   `BrandLink` по типам (website верифицируется HTTP, если не `--no-verify`; соцсети — нет),
   `BrandStore` (адрес+город+телефон). Если что-то записано → `markContentChanged` (ре-доставка).

**`Brand.contactStatus`** (по `confidence`):

| confidence | status | смысл |
|---|---|---|
| `high` / `medium` | `enriched` | найдены контакты |
| `low` | `partial` | мало данных |
| `not_found` | `not_found` | **терминальный**, не повторяем |
| (ошибка запроса) | `error` | retry до 3 раз (`MAX_ERROR_RETRIES`) |

Также пишутся `contactEnrichedAt`, `contactAttempts++`.

> **Почему известный бренд может стать `not_found`?** Discovery не нашёл его **настоящий** сайт →
> в корпус попала мусорная/чужая страница → модель честно отвечает «контактов нет». Лечение: разбор
> в `/admin/rag/review` (для контентных отказов) или ручное добавление верного URL в очередь (§9) с
> последующим `--force` переобогащением.

**Параметры**: `limit` (деф. 50), `--id`, `--force` (переобработать enriched/partial),
`--no-verify` (без HTTP-проверки URL), `--dry-run`.

---

## 7. Оркестрация (rag:daemon)

`RagDaemonCommand` (`app:rag:daemon`) — оркестратор боевого флоу. Бесконечный цикл; каждая стадия —
**отдельный PHP-процесс** (паттерн cron-демона): ребёнок отработал → умер → вся его память
(Doctrine-профайлер, UoW, фрагментация) освобождена ОС. Родитель только спавнит и стримит вывод.

**`STAGES`** (имя → команда + дефолт-аргументы):

| Стадия | Команда | Дефолт |
|---|---|---|
| `discover` | `app:brand:discover` | `30` |
| `crawl` | `app:brand:crawl` | `30` |
| `fetch` | `app:brand:fetch` | `--max-urls=250` |
| `embed` | `app:brand:embed` | `30` |
| `enrich` | `app:brand:enrich-contacts` | `10` |
| `generate` | `app:brand:generate-content` | `10 --grounded-only` |
| `faq` | `app:brand:faq` | `10` |
| `extract` | `app:brand:extract` | `10` |
| `push` | `app:brand:push` | `20` |
| `keywords` | `app:brand:keywords` | `90` (**не** в дефолтном цикле) |

- **Без `--stages`** → полный цикл всех стадий **кроме `keywords`** (одной командой `app:rag:daemon`,
  напр. после ребута, поднимается весь конвейер).
- **`--stages=имя:N`** переопределяет лимит (позиционный `30` или число в опции `--max-urls=250`).
- **Lock per НАБОР стадий** (`flock` на `var/rag_daemon-<стадии>.lock`): можно крутить параллельно
  демон сетевых стадий (`discover,crawl,fetch`) и GPU-стадий (`embed,generate`), чтобы GPU не
  простаивал. ⚠️ **Наборы разных демонов не должны пересекаться** (иначе двойная работа); одинаковые
  наборы — коллизия lock.
- `CHILD_TIMEOUT_SEC=7200` — потолок на стадию (зависший ребёнок не блокирует демон).
- Стадия упала с ненулевым кодом → демон **не** падает (следующий цикл доберёт, статус-машина
  resumable).
- `--once` (один цикл, для теста), `--sleep` (пауза между циклами, деф. 60с).

> **⚠️ `--no-debug` обязателен** (демон добавляет его дочерним процессам автоматически, плюс флаг в
> CLAUDE.md для ручных батчей). В dev-режиме Doctrine-профайлер копит каждый SQL с backtrace в
> памяти (`BacktraceDebugDataHolder`) → OOM на сотнях брендов при 512M. `gc_collect_cycles()` не
> помогает — это живые ссылки профайлера. Ручной долгий батч:
> `php -d memory_limit=512M bin/console app:brand:keywords 6000 --no-debug`.

---

## 8. Подводные камни (gotchas)

1. **CAPS — это ДВА слоя, не «синхронная пара».** `DiscoverBrandSourcesCommand::CAPS` — cap по типу
   в **очереди** (суммарно по запускам); `BrandSourceFinder` (`T1_CAP`, `T2_MARKETPLACE_CAP`,
   `T2_MENTION_CAP`, `T3_*`) — cap **эмиссии за один вызов**. Большинство типов совпадает
   (own_site/marketplace/social/article_review), но: у finder **нет** отдельного cap для `catalog`
   (он свёрнут в bucket `mention` в T2), а `T2_MENTION_CAP=8` **превышает** командный `mention=6`
   (лишнее отсекает очередь). Менять с пониманием обоих слоёв, а не «копировать число».
2. **`RELEVANCE_FLOOR` (0.35) — НЕ порог gate.** Gate решает только `MIN_CHUNKS`/`MIN_SCORE` на
   cosine. FLOOR фильтрует чанки по payload-relevance уже **внутри** собранного контекста и
   откатывается, если опустошит набор (§5). Не путать «cosine score» (gate) и «payload relevance»
   (приоритизация).
3. **`--no-debug` на долгих батчах** — иначе OOM из-за Doctrine-профайлера (§7).
4. **source_type-таксономия** размазана: `BrandSourceUrl` (8 типов) → carry-forward в документ
   (default `official_site`, legacy) → Qdrant payload → `BrandRagService` считает `own_site` и
   `official_site` за одно (§4). Не предполагать единый словарь.
5. **`url_hash`, не `url`** — дедуп очереди по sha256(нормализованный URL); ручной INSERT обязан
   считать хэш так же (`BrandSourceUrl::normalizeHash`) или сеттером `setUrl()` (он сам считает).
6. **SearXNG — узкое место discovery.** Движки ловят CAPTCHA при частых запросах; finder делает
   паузу 1.5с между запросами, команда — 1с между брендами; circuit breaker рубит прогон после 3
   подряд лежащих SearXNG. Лежащий поиск → бренд **не** помечается discovered.
7. **Транслит/омонимы названий ломают discovery.** Co-occurrence-скоринг (имя бренда + fashion-термин)
   и deny-list (`диабет/медицин/…`) отсекают MariDeniz→диабет, но кириллица/латиница и редкие
   названия могут не находить настоящий сайт → корпус из мусора → `review`/`not_found`.
8. **`country.id UNSIGNED`** (из CLAUDE.md) к RAG-таблицам **не относится** — FK здесь `brand_id INT`
   (signed), как и `brand.id`.
9. **wb-enrich и keywords вне дефолтного демона** — отдельные запуски; `keywords` ограничен квотой
   Wordstat 100/час.
10. **0-документов бренд всё равно идёт до `embedded`/генерации** — там gate не пройдёт и (без
    `--grounded-only`) уйдёт в legacy-описание. С `--grounded-only` → `deferred`.

---

## 9. Ручное вмешательство

### Добавить URL в очередь (→ fetch подхватит)

Прямой INSERT в `brand_source_url`. Проще всего — через одноразовый скрипт/tinker-стиль, чтобы
сеттер сам посчитал `url_hash`:

```php
$u = (new BrandSourceUrl())
    ->setBrand($brand)
    ->setUrl('https://realbrand.ru')      // сам считает url_hash
    ->setSourceType(BrandSourceUrl::TYPE_OWN_SITE)
    ->setTier(BrandSourceUrl::TIER_OWN_SITE)
    ->setRelevanceScore(0.9)
    ->setStatus(BrandSourceUrl::STATUS_PENDING);
$em->persist($u); $em->flush();
```

Затем `php bin/console app:brand:fetch --id=...` нет (fetch шардит, не по id) — запусти
`app:brand:fetch` (он сдренит все pending). При сыром SQL-INSERT не забудь
`url_hash = sha256(нормализованный url)` (см. gotcha 5).

### Добавить документ напрямую (→ embed)

INSERT в `brand_source_document` (через сеттер `setCleanText()` — он считает `content_hash` +
`char_count`), затем `php bin/console app:brand:embed --id=<brandId> --force`. `--force` удалит
старые векторы бренда и переэмбеддит всё (документы с тем же `content_hash` дедуплицируются на
уровне уникум-индекса).

### Переобогатить контакты / перегенерировать

- Контакты: `app:brand:enrich-contacts --id=<id> --force` (переобработает даже `enriched`/`partial`;
  `not_found` тоже сбросится в обработку при `--force`).
- Контент: `app:brand:generate-content --id=<id>` (если description пуст — полная генерация;
  иначе meta-only ветка). `--dry-run` для предпросмотра.

### Админка `/admin/rag`

- **`/admin/rag`** — дашборд: воронка брендов, статусы пайплайна, очередь URL, прогресс стадий
  («сделано / за час / последняя активность / осталось»), срезы готовности, GSC, outreach. Цифры —
  живые из БД (демоны на LLM-сервере пишут туда же).
- **`/admin/rag/review`** — ручная верификация брендов в статусе `review` (контентный отказ
  модели). Действия (POST, CSRF):
  - **requeue** — сброс на пересбор: `pipeline → pending`, обнуление timestamp'ов/`source_count`/
    `has_own_site`, и `brand_source_url` `fetched`/`failed → pending` (перечтение). discover/fetch
    подхватят заново.
  - **hide** — soft-hide: `brand.status → inactive` + снятие с публикации на проде через агент-API
    (`/api/v1/brands/unpublish`, X-Agent-Token + HMAC). Fail-soft: прод недоступен → локальный hide
    всё равно применён.
- **`/admin/rag/brand`** — поиск бренда (id/slug/название) → **`/admin/rag/brand/{id}`**: панель
  ручного управления конвейером по одному бренду («подсказать вручную»). Все действия — POST с CSRF
  (`rag_brand_{id}`); меняют только состояние в БД, фактическую обработку делает `rag:daemon`/cron в
  ближайшем цикле (web-запрос не блокируется на GPU/LLM). Кнопки и формы (`RagDashboardController`):
  - **Статусы этапов** discover/fetch/embed/generate + кнопка «↻ заново» у каждого: сброс на
    повторный прогон этапа (discover → `pending`+`discovered_at=NULL`; fetch → URL `fetched/failed/
    skipped → pending`; embed → документы `embedded=0` + `status=scraped`; generate → `status=embedded`).
  - **Вставить факт-текст** → создаёт `brand_source_document` (`source_type=own_site`, `relevance=1.0`,
    `url=manual://admin`, дедуп по `content_hash`) и ставит `pipeline.status=scraped` → embed → generate.
    Это прямой ответ на «давать факты вручную».
  - **Добавить URL** → `brand_source_url` (pending, дедуп по `url_hash`, `relevance=0.9`, tier по типу) →
    fetch подхватит. Подсказка правильного сайта, который discovery не нашёл.
  - **Исправить название/slug** + повторный discover (фикс транслита: кейс дубля «Snezhnaya Koroleva» 786
    — discovery искал латиницу, реального сайта не нашёл; кириллический дубль 551 обогатился нормально).

### Подбор брендов / вопрос по каталогу — `app:brand:ask`

```bash
# Подбор по запросу (1 аргумент): эмбеддинг → глобальный поиск brand_chunks → LLM-реранк
php bin/console app:brand:ask "подбери бренд под casual/office для женщины" --local --limit=5
php bin/console app:brand:ask "минималистичные пуховики из Питера" --local --raw   # без LLM, топ по близости

# Вопрос про конкретный бренд (2 аргумента): RAG по фактам этого бренда
php bin/console app:brand:ask "Снежная Королева" "из чего шьют пуховики?" --local
```

`VectorStoreService::search()` — глобальный поиск по всей коллекции (без фильтра `brand_id`),
группировка чанков по бренду, шортлист 15 → LLM-реранк (`think:false`). ⚠️ Видны только бренды,
прошедшие `embed`; чистый вектор не гарантирует жёсткие фильтры («женщина») — реранк отсекает мусор
по фактам. Апгрейд при шумной выдаче — структурный пред-фильтр по `BrandAudience`/`BrandStyle`.
