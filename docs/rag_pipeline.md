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
wb / logo / push), которые читают тот же корпус и пишут свои поля статуса.

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
        app:brand:logo       лого из HTML own_site/маркетплейс → brand.logo  (logoStatus)    │
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
| `STATUS_SCRAPED` | `scraped` | `fetch` (finalize, когда очередь дренирована) | Корпус собран (≥1 документ; **0 документов → `dead`**) |
| `STATUS_EMBEDDED` | `embedded` | `embed` | Чанки залиты в Qdrant |
| `STATUS_GENERATED` | `generated` | — (зарезервирован; код пишет сразу `done`) | — |
| `STATUS_DONE` | `done` | `generate-content` | Description + meta сгенерированы |
| `STATUS_SCRAPE_FAILED` | `scrape_failed` | — | (зарезервирован) |
| `STATUS_EMBED_FAILED` | `embed_failed` | `embed` (ошибка) | Сбой эмбеддинга, `embedAttempts++` |
| `STATUS_GENERATE_FAILED` | `generate_failed` | — | (зарезервирован) |
| `STATUS_DEFERRED` | `deferred` | `generate-content --grounded-only` | Корпус не прошёл gate → ждём дозревания |
| `STATUS_REVIEW` | `review` | `generate-content` (refusal) | Модель отказала (факты не о бренде) → ручная верификация, **не публикуем** |
| `STATUS_DEAD` | `dead` | `fetch` (finalize, 0 корпуса) / `app:brand:rediscover` | Корпус невозможен (все источники мертвы/skipped). Терминал, исключён из всех стадий; реверсивно (reset в pending при возврате discover) |

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
| `logoStatus` | `LOGO_FOUND` / `LOGO_NOT_FOUND` (страницы перебраны, годного лого нет) / `LOGO_SKIPPED` (нет URL-кандидатов) / `LOGO_FAILED` (сеть, повторяемо) | `logo` | не искали лого |

Соответствующие timestamp-поля: `discoveredAt`, `scrapedAt`, `embeddedAt`, `generatedAt`,
`keywordsCheckedAt`, `wbCheckedAt`, `crawledAt`, `extractedAt`, `logoCheckedAt`. Счётчики попыток: `scrapeAttempts`,
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

### Приоритет очереди (`priority`)

Ручной приоритет «чем больше — тем раньше» проходит бренд **сквозь все этапы**:

- **`brand_rag_pipeline.priority`** (int, деф. 0) — приоритет на уровне бренда. Стадии
  embed/extract/generate/keywords/crawl/faq/push выбираются через
  `PipelineQueueRepository::finishStageQuery`, который сортирует `p.priority DESC` → бренд несёт
  приоритет сквозь них автоматически (одна строка pipeline на бренд, статус меняется — priority нет).
- **`brand_source_url.priority`** (int, деф. 0) — денормализация для **fetch**: он клеймит URL через
  `claimPending` (сырой SQL по `brand_source_url`, **минует pipeline**), поэтому priority
  «перекидывается» на URL. Проставляется при enqueue в discover (из priority бренда) и
  пропагируется `BrandSourceUrlRepository::propagatePriority($brand, $p)` при смене приоритета уже
  отдискаверенного бренда. `claimPending` сортирует `priority DESC, tier ASC, relevance_score DESC`.
- **Кто ставит**: `EvaluateExperimentsCommand` (loss-ветка closed-loop → `max(50, …)`); ручные
  батчи. Для НОВОГО высокоприоритетного бренда: выставить `pipeline.priority` до discover → URL
  заштампуются приоритетом → fetch и далее все стадии возьмут его первым.

---

## 3. Этапы по порядку

### 3.0 `app:brand:discover` — discovery URL-кандидатов

`DiscoverBrandSourcesCommand`. Через `BrandSourceFinder::discoverTiered()` (SearXNG + опц. Yandex
Search API + DB-ссылки) находит URL-кандидаты бренда **без скачивания страниц** и кладёт в очередь
`brand_source_url` (дедуп по `url_hash`, cap по `source_type`).

**Источники поиска (мёржатся по URL, дедуп):**
- **Yandex Search API — ПЕРВИЧНЫЙ** (`YandexSearchClient`, `YANDEX_SEARCH_API_KEY` +
  `YANDEX_SEARCH_FOLDER_ID`): официальный Yandex Cloud Search API v2
  (`searchapi.api.cloud.yandex.net`), **внешний, НЕ зависит от .43** → discover работает с Mac
  даже когда .43 выключен. no-op если ключ/folder не заданы.
- **SearXNG — ВСПОМОГАТЕЛЬНЫЙ** (`SEARXNG_URL`, :8080 на **.43**): дополняет выдачу. Его падение
  НЕ фатально, если Yandex отработал. Circuit breaker (3 бренда подряд) срабатывает только когда
  **оба** источника мертвы (или Yandex не настроен + SearXNG лёг).

- **Вход**: активные бренды (`status ∈ {active, new}`) без `discovered_at`.
- **Выход**: строки `brand_source_url` (status=`pending`); `pipeline.has_own_site` (provisional),
  `discovered_at`. **Не трогает `pipeline.status`.**
- **Параметры**: `limit` (брендов/запуск, деф. 50), `--id`, `--max` (кандидатов/бренд, деф. 50),
  `--shard`/`--total` (шардинг для параллельных процессов), `--dry-run`.
- **CAPS** (макс. URL данного типа в очереди у бренда, суммарно по запускам): единый источник
  `BrandSourceUrl::ENQUEUE_CAPS` (на него ссылаются и enqueue в команде, и `BrandSourceFinder`):
  `own_site=2`, `marketplace=5`, `catalog=6`, `article_review=5`, `social=6`, `mention=6`.
  (T2-mention в finder = 8 — намеренный emission-headroom: эмитим больше, enqueue урезает до cap.)
- **Priority**: при enqueue каждому URL проставляется `priority` бренда (из `brand_rag_pipeline.priority`)
  → fetch берёт высокоприоритетные первыми. См. §«Приоритет очереди».
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
| Logo | `app:brand:logo` | Лого из HTML own_site/маркетплейс → `brand.logo` | `logoStatus`, `logoCheckedAt`, `contentChangedAt` |
| Push | `app:brand:push` | Доставка готовых брендов на прод (агент-API + HMAC) | `pushedAt`, `pushAttempts`, `pushError` |

> **keywords** — отдельный долгоживущий демон (квота Wordstat 100/час, ~56 мин/цикл), **не** входит
> в дефолтный цикл `rag:daemon`. **wb-enrich** (`app:brand:wb-enrich`) — реальная стадия, но в
> `RagDaemonCommand::STAGES` её **нет** (запускается отдельно/вручную).

### 3.6 `app:brand:logo` — поиск и извлечение логотипа

`FetchBrandLogoCommand`. Достаёт логотип бренда и кладёт в `brand.logo`. **Корпус
(`brand_source_document`) для лого бесполезен** — это чистый ТЕКСТ, где `img`/`svg`/`header`/`og`/
JSON-LD вырезаны при чистке, а сырой HTML не хранится (§4). Поэтому стадия **заново фетчит HTML**
страницы (1 запрос/бренд) через `WebScraperService::fetch()`; из корпуса переиспользуется только
**список URL** (`brand_source_url`), не контент. Не зависит от LLM-сервера (только фетч сайтов +
скачивание картинок).

- **Вход**: активные/`new` бренды без `logo` (`BrandRepository::findForLogo`), у которых лого ещё
  не искали либо был сетевой сбой (`logoStatus ∈ {null, failed}`). `not_found`/`skipped` —
  терминальны без `--force`.
- **URL-кандидаты страниц** (в порядке приоритета, дедуп, cap 4): own_site (`brand_source_url`
  type `own_site`, по `relevance`) → `BrandLink` website → `marketplace` (WB/Lamoda/vitrine).
- **Извлечение** (`LogoExtractor`, парсинг HTML): скоринг источников
  JSON-LD `Organization.logo` (100) → `og:logo` (90) → `<img>` с «logo» в src/alt/class/id (80) →
  `apple-touch-icon` (60) → `og:image` (40) → favicon (20). Абсолютизация, дедуп, сортировка.
- **Скачивание+валидация** (`LogoFetcher`): формат png/jpg/webp/gif/svg; raster ≥120px (favicon
  ≥48px — мягкий fallback); отсев баннеров (соотношение сторон >6:1) и битых файлов; ≤2 МБ. SVG —
  вектор, без проверки размера. Перебор кандидатов по убыванию score до первого валидного
  (cap 6 скачиваний/бренд).
- **Выход**: файл в `public_html/images/logos` (имя `logo_{brandId}_{sha8}.{ext}`, детерминировано
  по содержимому → нет дублей), `brand.logo` + `markContentChanged` (→ push довезёт лого как base64,
  см. `BrandPayloadAssembler`). `logoStatus` + `logoCheckedAt`.
- **Параметры**: `limit` (деф. 30), `--id`, `--force` (вкл. not_found/skipped и с уже выставленным
  logo), `--dry-run`, `--shard`/`--total`.
- **vitrine.market** (конкурент-каталог) **разрешён** как источник лого (аватарка там — обычно
  реальный логотип бренда), но как **website-ссылка** он заблокирован (deny-лист в enrich, §6).

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
| `priority` | int (деф. 0), перекинут из `brand_rag_pipeline.priority` → порядок `claimPending` (fetch) |
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

**Гард топикальности (анти-омоним).** После прохождения cosine-gate собранный контекст проверяется
на наличие хоть одного fashion/commerce-сигнала (`FASHION_SIGNALS`: одежд/коллекц/магазин/ткань/
fashion/shop…). Если НЕТ ни одного — корпус почти наверняка про **омоним** (страна Mauritius,
браузер Vivaldi, страховая Wysh), а не про бренд одежды → `context=null` (не заземляем, grounded-only
→ `deferred`). Ловит чужую сущность ДО генерации; refusal-гейт (`ContentValidator`) — последняя сетка.

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
4. **Промоут own_site → website-ссылка**: если LLM не вернул website (а в тексте корпуса полного
   URL сайта обычно нет, хотя сам сайт мы скрейпили как own_site), бренду проставляется
   подтверждённый own_site (`brand_source_url`) как `BrandLink` type `website`. Защита: deny-лист
   маркетплейсов/агрегаторов (`MARKETPLACE_HOSTS`: vitrine.market, wildberries, ozon, lamoda,
   market.yandex, megamarket, aliexpress, flowwow) — discovery иногда метит их `own_site`, но это
   не сайт бренда, в website их не пускаем.

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
| `logo` | `app:brand:logo` | `20` |
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

### 7.1 Автоскейлер-супервизор (`app:rag:autoscale`)

`RagAutoscaleCommand` — кроновый супервизор демонов (`*/3 * * * *`, Mac). Идемпотентный **реконсайл**:
сверяет желаемое состояние с живыми `app:rag:daemon` (по `pgrep -fl`, разбор `--stages`/`--shard`) и
доводит до него — спавнит недостающее, гасит лишнее, респаунит упавшее. `--dry-run` — показать план.

**Baseline (всегда-живые, по 1 на набор):**
- `BASELINE_NET = crawl,fetch,logo,push,enrich` — сеть/IO, gemma не трогает; живёт **всегда**.
- `BASELINE_GPU = embed:200,generate,faq,extract` — поднимается **только при живом .119**
  (TCP-проба host:port из `LOCAL_LLM_URL`). Мёртв → gpu гасится, остаётся net-only: не жжём
  `generate`-attempts о недоступный сервер. `embed:200` — лимит 200 (мелкая qwen-0.6b давит backlog
  документов, не ждёт медленные LLM-стадии). net∪gpu = полный конвейер (без `keywords` — отдельно).
- Раздельны (а не один «полный» демон) → GPU не голодает, деля цикл с медленными net-стадиями.
- Нераспознанный базовый набор (напр. ручной демон с другим составом) **прибивается** как чужой.

**Burst (только net-стадии):** при заторе поднимает доп. шард-воркеры `ceil(queue/per)`, ≤ `max`,
в рамках бюджета ядер (`nproc − резерв(1) − baseline(1)`). Сейчас активен только `fetch`
(порог 2000 pending-URL, per 1500, max 3). Шарды дренят без коллизий через `claimPending`
(`FOR UPDATE SKIP LOCKED`). **`generate`/embed НЕ бёрстятся** — один LLM-сервер, переподписка роняет
gemma (см. [[llm-server-oversubscription]]).

**discover ОТКЛЮЧЁН** в baseline и burst (Yandex Search API платный). Вернуть — раскомментить
`discover,` в начале `BASELINE_NET` + блок в `BURST` (см. комментарии в коде).

**health-gate:** при мёртвом .119, если в очереди есть embed/generate-работа — варнинг (иначе
GPU-стадии «молча стоят»).

> **⚠️ autoscale vs `bin/mac-rag-start.sh` — взаимоисключающие способы запуска.** Ручной скрипт
> поднимает свою split-топологию (`embed,generate,enrich,faq,extract` ‖ `discover,crawl,fetch,logo,push`)
> — наборы стадий НЕ совпадают с autoscale-baseline, поэтому при активном кроне autoscale прибьёт
> ручные демоны как «нераспознанные». Использовать что-то одно: либо cron-autoscale (саморегулируется),
> либо ручной скрипт (когда кроне autoscale не установлен). Проверить кто живёт: `pgrep -fl app:rag:daemon`.
> Логи autoscale-воркеров: `var/log/autoscale-{baseline-net,baseline-gpu,burst-fetch-N}.log`.

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

### Ре-валидация уже-`done` контента (выловить протёкшие отказы)

`app:brand:revalidate-content` — прогоняет описания `done`-брендов через `ContentValidator::isRefusal`
и демотирует протёкшие отказы (корпус-омоним: модель отказала, но текст уехал на прод) в `review`
+ снимает с публикации на проде (агент-API). Запускать после ужесточения refusal-паттернов.
```bash
php bin/console app:brand:revalidate-content --dry-run      # только показать протечки
php bin/console app:brand:revalidate-content                # demote в review + unpublish
php bin/console app:brand:revalidate-content --id=3818      # один бренд
php bin/console app:brand:revalidate-content --no-unpublish # demote локально, прод не трогать
```

### Скрыть бренд с публикации из Telegram

Дневной отчёт (`app:report:daily`, крон Mac) шлёт по свежеопубликованным (24ч) бренду сообщение с
inline-кнопкой **«🚫 Скрыть с публикации»** (`callback_data=unpub:<id>`). Нажатие → `TelegramController`
вебхук `callback_query` → `BrandUnpublisher::hide()`: локальный soft-hide (`status=inactive`) +
unpublish на проде. Защита: действие только из админ-чата (`AdminNotifier::isAdminChat`). ⚠️ TG из РФ
ходит только с Mac — поэтому уведомление-с-кнопкой шлёт Mac-крон, не прод-publish-tick.

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

---

## 10. Версионирование контента + closed-loop регенерация (2026-06-14)

Цель: безопасно перегенеривать тонкий/недоиндексированный контент, **не теряя текущий**
и **не ломая работающие страницы**, с обратной связью от поиска.

> **2026-07-03 — переориентация на Яндекс.** Покрытие Google заморожено с ~12.06 → все
> оценки уходили в `not_indexed`, цикл не вынес НИ ОДНОГО вердикта по rag-экспериментам.
> Теперь метрика комбинированная (`BrandContentVersioner::gscSnapshot`): «в индексе» =
> `in_search` Яндекса ИЛИ `indexed` GSC; показы/клики = GSC + Яндекс-запросы, матчированные
> LIKE по имени бренда (`yandex_query_stats` — домен-wide топ-500, brand_id нет → длинный
> хвост без показов, это ок). Вход страницы в индекс при показах < MIN_SAMPLE = `win`
> (главный исход эксперимента). Гард свежести — на оба синка (`app:gsc:sync` И
> `app:yandex:sync`). Поля ревизий `gsc_*` хранят комбинированную метрику.
> Детали снимка: Яндекс-показы — ТОЛЬКО последнее окно (`date_to = MAX`; синк апсертит
> перекрывающиеся окна, сумма по датам задваивала бы показы); имена < 3 символов не
> матчатся (LIKE-шум); `in_search` — только строки свежее 3 дней от последнего прогона
> синка (строки не зануляются — выпавший из поиска бренд иначе «в индексе» навечно).
> Pending-базлайны перемерены 2026-07-03 одноразовым UPDATE в комбинированную шкалу
> + окна переоткрыты (+14д): иначе 235 брендов, уже сидевших в Яндексе до ревизии,
> дали бы ложный win «вошла в индекс». Первые вердикты по новой шкале — с 17.07.

### Модель данных
- **`brand_content_revision`** (append-only) — история тройки `description + meta_title +
  meta_description`. Поля: `source` (legacy|rag|manual|import|rollback), `grounded`,
  `retrieval_score`, `is_active` (зеркалит живые `brand.*`), `attempt`, `prev_revision_id`,
  `created_at`, `measure_after`, `verdict` (pending|win|loss|neutral|not_indexed), `loss_streak`
  (окон loss подряд — антифлаппинг) и снимки GSC `gsc_{impr,clicks,indexed}_{before,after}`.
  Live-значения остаются в `brand.*` — read-path сайта не трогаем; активная ревизия их дублирует.
- **`BrandContentVersioner`** (`src/Service/`): `ensureBaseline()` (снять текущее как `legacy`,
  чтобы не потерять), `record()` (новая активная ревизия + старт эксперимента: baseline GSC +
  **окно по попытке** `windowDays(attempt)`: attempt 1 → 28д, 2 → 21д, далее → 14д — молодым
  страницам Google нужен разгон, меньше ложных loss), `rollback()` (append-only — пишет новую
  ревизию `source=rollback`). Снимок GSC из `gsc_page_stats`/`gsc_index_status`.
- ⚠️ `brand.content_version` переименован в **`agent_sync_version`** — это sequence-номер
  доставки в агент-API, НЕ версии контента (раньше путало).

### Где встроено
- `generate-content`: контент пишется только после quality-gate (refusal → validateDescription →
  article-QA → near-duplicate). На записи: `ensureBaseline()` ДО перезаписи + `record()` ПОСЛЕ →
  промоут новой активной ревизии. Флаг **`--protect-performing`** пропускает бренды с показами в
  GSC (работающее не ломаем), `--force` обходит.
- `brand_rag_pipeline.priority` (INT, default 0): ручной приоритет очереди, `priority DESC` —
  первичная сортировка во всех этапах (`BrandRepository::finishStageQuery`). Поднять бренд:
  `UPDATE brand_rag_pipeline SET priority=N WHERE brand_id=…`.
- `app:brand:backfill-content-revisions` — одноразовый baseline `legacy` для текущих брендов.

### Closed-loop (дерево решений эксперимента) — по `_seo/SEO_Guide_4.9` (growth-loop)
`EvaluateExperimentsCommand`. После истечения окна (`measure_after`) сверяет GSC variant vs baseline.

**0. Гард свежести GSC** (в начале): `MAX(gsc_page_stats.day)` старше `GSC_STALE_DAYS=5` (или нет
данных) → команда **аварийно выходит** (exit≠0), НЕ судит. Иначе тихий сбой `gsc:sync` дал бы нули →
весь каталог ушёл бы в ложный `not_indexed`. GSC сам лагает ~2-3 дня, порог 5 — с запасом.

1. **not indexed / impressions < MIN_SAMPLE (10)** → `not_indexed`: контент не виноват (Google не
   дал шанс). **НЕ терминально:** если ревизия младше `MAX_INDEX_WAIT_DAYS=60` → verdict остаётся
   `pending`, окно переоткрывается (+`RE_MEASURE_DAYS=14`) → переоценим, когда `index-ping` доведёт
   до индекса и пойдут показы. Терминальный `not_indexed` — только после 60 дней ожидания.
2. **судим с порогом** (относит. 20% + абсолютный пол: clicks ±2 / impr ±10, отсечь шум):
   `loss` (clicks/impr упали > порога) · `win` (clicks не упали И (impr ↑ или впервые в индексе))
   · иначе `neutral`.
3. **действие:** win/neutral → оставить.
   **`loss` — антифлаппинг:** первый `loss` НЕ реагирует — `loss_streak++`, окно переоткрывается
   (+14д), ждём подтверждения. Только при **`loss_streak ≥ LOSS_CONFIRM_WINDOWS=2`** (loss 2 окна
   подряд) → вилка: `attempt < MAX_ATTEMPT=3` и есть grounded-корпус (≥3 док) → **регенерация**
   (флаг `regen_requested_at` + `priority≥50`, новый эксперимент); иначе → **откат** к лучшей
   ревизии (`findRollbackTarget`: последний подтверждённый `win`, иначе самая свежая прошлая).

### Автоматизация (scheduled_command, env=dev — диспетчер Mac тикает ежеминутно)
Контур самокрутится:
- `0 3` — `generate-content 50 --regen-flagged --protect-performing` (форс-реген проигравших по флагу `regen_requested_at`, ночью);
- `0 8` — `gsc:sync --report` (замер: показы/индекс + sitemaps);
- `0 10` — `seo:evaluate-experiments` (тик closed-loop: win/loss/neutral/not_indexed → keep/откат/реген-флаг);
- `0 11` — `google:index-ping` (пуш not-indexed).

⚠️ **Массовую генерацию бэклога** (82 grounded-ready тонких, 425 embedded, 3475 без описания)
гоняем вручную `nohup`'ом (CLAUDE.md: тяжёлые батчи не в минутный диспетчер — длинный джоб держит
глобальный flock и съедает минутно-точные задачи). ~24 бренда/час; требует поднятого .43 (ollama):
`nohup php -d memory_limit=512M bin/console app:brand:generate-content <N> --grounded-only --protect-performing --no-debug >> var/log/gen.log 2>&1 &`.

### Статус
✅ Всё внедрено на Mac и проде: версии/versioner/wire-in/backfill/priority/protect-performing/rename +
   `evaluate-experiments` + `--regen-flagged` (флаг `regen_requested_at`) + джобы в scheduled_command.
