# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

WEARBASE — каталог российских брендов одежды с поддержкой международных рынков.
Stack: **Symfony 7.3 · PHP 8.2+ · MySQL 9.1 · Doctrine ORM · EasyAdmin 4 · Twig · AssetMapper**.

## Commands

```bash
# Dev server (Symfony CLI)
symfony serve

# Clear cache
php bin/console cache:clear

# Run migrations
php bin/console doctrine:migrations:migrate

# Generate a new migration from entity changes
php bin/console doctrine:migrations:diff

# Update exchange rates from CBR (run daily via cron)
php bin/console app:currency:update-rates --dry-run   # preview
php bin/console app:currency:update-rates             # apply

# PHPUnit (functional tests, no DB — uses test env)
php bin/phpunit
php bin/phpunit tests/Controller/BrandLkControllerTest.php   # single file
php bin/phpunit --filter testBrandProfile                    # single test

# Playwright E2E (requires running dev server at wearbase.dev.local)
npm install && npx playwright install chromium
npx playwright test
npx playwright test tests/e2e/02-locale-switch.spec.ts       # single spec
BASE_URL=http://localhost:8000 npx playwright test           # custom base URL
```

## Окружение Mac (dev-машина)

> Из аудита сессий 2026-07-04 ([docs/session_friction_audit.md](docs/session_friction_audit.md)) — эти грабли ловились каждую сессию.

- **PHP**: `/opt/homebrew/bin/php` (запасной — `/opt/homebrew/opt/php@8.2/bin/php`). В cron/скриптах PATH пуст — всегда полный путь, не перебирать варианты.
- **memory_limit**: дефолт Homebrew 128M. `cache:clear` и любые батчи — только с `-d memory_limit=512M`.
- **yt-dlp**: `/opt/homebrew/bin/yt-dlp` (youtube-dl не установлен). **trafilatura** — в venv, путь экспортит `bin/mac-rag-start.sh`; пути в `.env.local` серверные, им не верить.
- ⚠️ **`dbal:run-sql`**: SQL строго одной строкой (многострочный молча даёт «0 rows affected»); «0 rows affected» в ответ на SELECT — артефакт раннера, НЕ доказательство пустой таблицы.
- `sleep` в Bash-инструменте блокируется — ожидания делать фоновой командой (`run_in_background`).

## Карта хостов

| Хост | Что там | Доступ |
|---|---|---|
| LLM-сервер / майнинг-риг | ollama :11434, Qdrant :6333, SearXNG :8080, wildrig, дашборд :8088 | `ssh llm` (сейчас 192.168.0.111; **IP непостоянен** — при недоступности сканировать подсеть на порт 11434 и обновить `~/.ssh/config` + память) |
| Прод regru | wearbase.ru | `ssh regru`; **TG с прода заблокирован** — все Telegram-уведомления шлёт Mac |
| Mac | dev, крон-диспетчер (`app:cron:run-scheduled` ежеминутно), TG | локально |

- «Host key verification failed» после смены IP → `ssh-keygen -R <ip>`, не бороться вслепую.
- На LLM-сервере диск и RAM ограничены (модели ~20 ГБ не влезают, диск забивался в 100%) — перед скачиванием моделей проверять `df -h` и свободную RAM.

## Правила взаимодействия

1. **Инициатива**: очевидный следующий шаг делать сразу, без «Хочешь, я…?». Вопрос — только перед необратимым, дорогим или внешне-видимым действием. (Уточнение к «Think Before Coding» ниже: неясны требования — спрашивай; ясен следующий шаг — действуй.)
2. **Фиксация знаний**: каждый research-вывод (сравнение моделей, «нужен ли reranker», разбор поведения поисковиков) — сразу в `docs/` или память, в ответе — ссылка на файл. Незаписанный вывод = тот же вопрос через день.
3. **Авто-коммит**: после каждой законченной правки — атомарный коммит без напоминания. Пуш и деплой — по запросу (или скиллом `/deploy`).
4. **Формат ответов**: одно сообщение ≤~4000 символов (читается в Telegram), вывод — первым абзацем, ход рассуждения — в тексте ответа.

## Architecture

> Полный индекс документации (`docs/`) — **[docs/README.md](docs/README.md)**.

### Прод и деплой

Сервер, env-карта (SMTP/Turnstile/GSC/Telegram), процедура деплоя и известные прод-проблемы — **[docs/production.md](docs/production.md)**. Не лазить на прод за тем, что уже описано там.

### Public directory

`public_html/` — not the default `public/`. Configured in `composer.json` (`"public-dir": "public_html"`).

### Two firewalls, two User entities

```
/admin  →  firewall: admin   →  Nevinny\AdminCoreBundle\Entity\User
/*      →  firewall: main    →  App\Entity\User
```

Never mix them. Admin routes use `admincore_login`/`admincore_logout`; front-end uses `app_login`/`app_logout`. Admin CRUD controllers extend `AbstractCrudController` from EasyAdmin and are registered in `src/Controller/Admin/DashboardController.php`.

Role hierarchy: `ROLE_BRAND_OWNER > ROLE_BRAND_MANAGER > ROLE_USER > ROLE_CUSTOMER`.

### Templates: single Tailwind stack, two layouts

| Layout | Used for |
|---|---|
| `templates/tailwind/base.html.twig` | Public pages: home hub, brands, catalog, blog, landings, legal (SEO head, full footer) |
| `templates/tailwind/app.html.twig` | Auth, account, cart, checkout, brand LK, brand claim (compact header, mini footer, `noindex`) |

Tailwind is loaded via CDN (`cdn.tailwindcss.com?plugins=typography`). Bootstrap was fully removed on 2026-06-12 (`templates/base.html.twig` and the bootstrap components are deleted — do not resurrect).

Conventions:
- Auth pages use the split-panel shell `tailwind/auth/_shell.html.twig` via `{% embed %}`.
- Account pages extend `account/layout.html.twig` (sidebar), brand LK pages extend `brand_lk/layout.html.twig` (dark sidebar); both extend `tailwind/app.html.twig`.
- The canonical brand page is `tailwind/brand/show.html.twig` (former `showv3`; v1/v2 deleted).
- Style tokens: card `rounded-2xl bg-white shadow-sm`; label `text-[11px] font-semibold uppercase tracking-wider text-gray-500`; input `rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:ring-2 focus:ring-gray-900`; primary button `rounded-xl bg-gray-900 text-white font-semibold hover:bg-black`.
- Flash messages render globally in both layouts — don't add per-page flash loops.
- Error blocks carry the semantic class `form-error` (tests assert on it).

### Locale & routing

All public pages use `/{_locale}/` as a route prefix with `requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko']`. The locale is resolved in this priority order by `LocaleListener` (priority 20, runs after RouterListener at 32):

1. Cookie `locale`
2. `Accept-Language` header
3. Default language from `language` table
4. Hard fallback: `ru`

To switch language, POST to `/locale/switch` with field `locale=en`. The controller rewrites the locale prefix in the Referer URL so RouterListener picks it up correctly on the next request.

**SEO непереведённых локалей:** `brand_translation`/`product_translation` пусты → все не-ru локали отдают русский fallback. Чтобы не плодить scaled-content дубли, `base.html.twig` ставит `noindex, follow` на **любую не-ru локаль** (canonical всё равно → `/ru/…`, sitemap — только ru). ru индексируется как обычно. Подробности и как откатывать при появлении реальных переводов — **[docs/international.md](docs/international.md)** (раздел «SEO непереведённых локалей»).

### Currency

All prices are stored in **RUB**. Conversion is done on the fly by `CurrencyConverter` (cached 1h in Symfony Cache). The selected currency is stored in cookie `currency` via `CurrencySession`. Twig globals `app_currency` and `currencies_list` are provided by `CurrencyExtension`.

Use the Twig filter `|price` (registered in `CurrencyExtension`) for displaying prices. Never store converted amounts in DB.

### Database FK constraint: `country.id` is `INT UNSIGNED`

`country.id` is declared `INT UNSIGNED NOT NULL AUTO_INCREMENT`. Any FK column pointing to it **must** also be `INT UNSIGNED NOT NULL`, or MySQL will throw error 3780. `brand.id` and `product.id` are plain `INT` (signed).

### Удаление данных: ТОЛЬКО soft-delete

**Никогда не делаем физический DELETE по действию пользователя** (ЛК, админка, публичные формы). Только soft-delete:
- через поле `status` (`Statuses::Deleted` у сущностей с трейтом `Status`), или
- через отдельное поле `deleted_at DATETIME NULL` (у простых сущностей).

Выборки обязаны фильтровать удалённое (`deleted_at IS NULL` / `status != 'deleted'`). Физический DELETE допустим только в системных операциях (миграции, delete-and-replace в импортах/агент-API, чистка осиротевших строк кронами).

### Migrations

Migrations use `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE` for idempotency. Use descriptive names: `Version20260523_phase5_brand_market.php`. Never use `php bin/console doctrine:schema:update`.

### Twig translation helpers

`TranslationExtension` provides two Twig functions:
- `brand_t(brand, 'field')` — returns the translated field for the current locale, falling back to the original Russian value.
- `product_t(product, 'field')` — same for Product.

Both cache DB lookups in-memory per request. For UI strings use standard Symfony `trans()` / `{% trans %}` with keys from `translations/messages.{locale}.yaml`.

### Entity traits (Nevinny AdminCore)

`Brand`, `Product` and similar entities use traits from `nevinny/admin-core`:
- `Status` — adds `status` field with `Statuses::Active` / `Statuses::Inactive` enum-like constants.
- `Created` — adds `created_at` / `updated_at`.
- `Owner` — adds `owner` (FK to admin User).

### File uploads

VichUploader handles brand logos and brand/product images. Mappings are in `config/packages/vich_uploader.yaml`. Uploaded files go to `public_html/images/logos/` and `public_html/images/brands/` with `SubdirDirectoryNamer` (2 levels, 2 chars each).

### International market entities

| Entity | Table | Purpose |
|---|---|---|
| `Language` | `language` | Supported UI languages |
| `Country` | `country` | Markets/countries (`INT UNSIGNED` PK) |
| `City` | `city` | Cities for shipping addresses |
| `Currency` | `currency` | All currencies; one is `is_base=true` (RUB) |
| `ExchangeRate` | `exchange_rate` | Daily rates from CBR; unique per (base, target, date) |
| `ShippingRule` | `shipping_rule` | Carrier + price per country |
| `TaxRule` | `tax_rule` | VAT/customs rules per country |
| `BrandMarket` | `brand_market` | Brand presence in a specific country |
| `BrandTranslation` | `brand_translation` | Translated brand content per locale |
| `ProductTranslation` | `product_translation` | Translated product content per locale |

### Payments & legal entities

Полная документация: **[docs/payments.md](docs/payments.md)**. Кратко: деньги за заказ идут напрямую бренду (без комиссии с продаж); подписки — на платформенные реквизиты YooKassa.

| Entity | Table | Purpose |
|---|---|---|
| `SellerLegalEntity` | `seller_legal_entity` | Юр.лицо продавца (1 бренд → N, с периодами действия) |
| `PaymentProvider` | `payment_provider` | Каталог платёжек (`is_active`); live только `yookassa` |
| `SellerPaymentAccount` | `seller_payment_account` | Счёт приёма: юр.лицо ↔ платёжка + зашифр. реквизиты |
| `OfferDocument` / `OfferAcceptance` | `offer_document` / `offer_acceptance` | Версионируемые оферты + факт акцепта (append-only) |

`Order` хранит снимки `sellerLegalEntity`/`sellerPaymentAccount`/`acceptedOffer` + поля возврата предоплаты (ЗоЗПП, 10 дней).

### Key services

| Service | Location | Purpose |
|---|---|---|
| `CurrencyConverter` | `src/Service/` | Convert/format prices; uses Symfony Cache |
| `CurrencySession` | `src/Service/` | Read/write selected currency cookie |
| `LlmService` | `src/Service/` | OpenRouter API for AI content generation |
| `ProductImportService` | `src/Service/` | Excel/CSV bulk import via PhpSpreadsheet |
| `PaymentService` | `src/Service/` | Платежи заказов (через шлюз бренда) + подписок + вебхук |
| `PaymentGatewayRegistry` | `src/Payment/Gateway/` | Резолв шлюза по коду провайдера (тег `app.payment_gateway`) |
| `SecretCipher` | `src/Service/` | Шифрование секретов счетов (libsodium, `PAYMENT_SECRET_KEY`) |

### RAG pipeline (контент брендов из реальных фактов)

Весь AI-стек на LLM-сервере (ollama: генерация — **`gemma4:26b`** (победитель бенча, см. [docs/model-ab-bench.md](docs/model-ab-bench.md): лучшее качество + влезает в одну карту; `LOCAL_LLM_MODEL`); эмбеддинг — qwen3-embedding:0.6b, Qdrant :6333, SearXNG :8080, trafilatura). ⚠️ IP сервера непостоянен (был 192.168.2.43, временно 192.168.0.119 — DHCP/переезд сети; при недоступности сканировать подсеть на порт 11434). Env в `.env.local`: `LOCAL_LLM_URL/MODEL`, `LOCAL_EMBED_URL/MODEL`, `QDRANT_URL/API_KEY/COLLECTION`, `SEARXNG_URL`, `TRAFILATURA_BIN`, `WORDSTAT_API_KEY`. **Поиск для discover: первичный — Yandex Search API (`YANDEX_SEARCH_API_KEY`/`YANDEX_SEARCH_FOLDER_ID`, внешний Yandex Cloud, НЕ зависит от .43 → discover работает с Mac при выключенном .43); SearXNG — вспомогательный.** trafilatura также есть локально на Mac (`TRAFILATURA_BIN`) → fetch работает без .43. Флоу: `discover → fetch → embed → generate-content` (статус-машина `brand_rag_pipeline`). **Полный технический reference конвейера (этапы, статус-машина, модель данных, gate качества, gotchas, ручное вмешательство) — [docs/rag_pipeline.md](docs/rag_pipeline.md).** Текущие cap'ы и история — `docs/tasktracker.md`.

⚠️ **Долгие батчи (сотни+ брендов) запускать с `--no-debug`**: в dev-режиме Doctrine-профайлер копит каждый SQL-запрос с backtrace в памяти (`BacktraceDebugDataHolder`) → OOM на ~750 брендах при 512M. `gc_collect_cycles()` не помогает — это живые ссылки профайлера.

```bash
php -d memory_limit=512M bin/console app:brand:keywords 6000 --no-debug
```

| Service | Location | Purpose |
|---|---|---|
| `BrandSourceFinder` | `src/Service/` | Tiered discovery URL-кандидатов (own_site→corpus→mentions), relevance-скоринг. Cap'ы по типу — единый источник `BrandSourceUrl::ENQUEUE_CAPS` (и finder, и `DiscoverBrandSourcesCommand` ссылаются туда; mention в T2 — намеренный emission-headroom) |
| `UrlFilter` | `src/Service/` | Единая точка исключений скрейпа: self-домены (wearbase.ru), job-/рекрутинг-хосты (`JOB_NOISE`), env `SCRAPE_EXCLUDED_DOMAINS`; suffix-match, fail-closed |
| `WebScraperService` | `src/Service/` | Fetch + HTML→текст (trafilatura, fallback DomCrawler), кеш 30д |
| `EmbeddingService` / `VectorStoreService` | `src/Service/` | ollama `/api/embed` → Qdrant (коллекция `brand_chunks`, payload brand_id/relevance) |
| `BrandRagService` | `src/Service/` | Retrieve top-k чанков + gate качества (chunks≥3 И score≥0.5, иначе legacy-генерация) |
| `KeywordService` / `WordstatClient` | `src/Service/Keyword/` | Yandex Wordstat `topRequests` → `brand_keyword`; топ-фразы подмешиваются в генерацию (title из топ-фразы) |

### Console commands

Полный справочник всех команд (зачем / как часто / где запускать + cron-сводка) — **[docs/commands.md](docs/commands.md)**.

### Brand contact enrichment

```bash
# Посмотреть что найдёт для 3 брендов (без сохранения)
php bin/console app:brand:enrich-contacts 3 --dry-run

# Запустить для 50 брендов (по умолчанию)
php bin/console app:brand:enrich-contacts

# Фоновый запуск 500 брендов с логом
nohup php bin/console app:brand:enrich-contacts 500 --quiet >> var/log/enrich.log 2>&1 &

# Один конкретный бренд по ID
php bin/console app:brand:enrich-contacts --id=42

# Переобработать всё (включая уже enriched/partial)
php bin/console app:brand:enrich-contacts 100 --force

# Быстро (без HTTP-проверки URL) — данные сохраняются как есть
php bin/console app:brand:enrich-contacts 200 --no-verify --quiet >> var/log/enrich.log 2>&1 &
```

**Cron** (рекомендуется для массового обогащения):
```
*/10 * * * * cd /path/to/project && php bin/console app:brand:enrich-contacts 30 --quiet >> var/log/enrich.log 2>&1
```

**Статусы обогащения** (`brand.contact_status`):
- `enriched`  — найден сайт или email (high/medium confidence)
- `partial`   — найдено что-то, но мало (low confidence)
- `not_found` — терминальный статус, не повторяем
- `error`     — ошибка запроса, повторяем до 3 раз

**Модель**: `perplexity/sonar` — использует тот же `OPENROUTER_API_KEY`.
Стоимость ~$1–2 на 1000 брендов.

### Tests

`tests/Controller/` contains PHPUnit functional tests using Symfony's `WebTestCase`. A `UserFactory` helper creates test users. The test environment uses a separate DB (`*_test` suffix). E2E tests live in `tests/e2e/` and use Playwright against a live dev server.

### OpenRouter / LLM

`LlmService` requires `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` env vars (set in `.env.local`). Not needed for most development tasks.

## Orchestration workflow

You (Fable) are the orchestrator. Plan, decompose, synthesize. Keep your own context lean —
delegate reading-heavy and generation-heavy work instead of doing it inline.

- **Reasoning-heavy phases** (architecture, debugging complex issues, algorithm design,
  tricky trade-offs) → subagent `deep-reasoner` (Opus).
- **Mechanical work** (boilerplate, tests, formatting, simple edits, repetitive refactors)
  → subagent `fast-worker` (Sonnet).
- **Domain work** (Symfony backend, Twig/emails, DB, SEO, deploy) → the specialized project
  agents (`backend-developer`, `frontend-developer`, `database-developer`, `seo-optimizer`,
  `devops`, …) take priority over generic ones.
- **High-stakes decisions**: run two independent `deep-reasoner` passes on the same problem
  in parallel (different framings), then synthesize the best of both — without showing either
  the other's answer.

Delegation does not suspend project rules: subagents inherit CLAUDE.md (Karpathy guidelines,
soft-delete only, migration conventions, branch-per-change workflow).

---

## Karpathy Skills — Coding Behaviour Guidelines

> Source: [forrestchang/andrej-karpathy-skills](https://github.com/forrestchang/andrej-karpathy-skills)
> Based on Andrej Karpathy's post: https://x.com/karpathy/status/2015883857489522876
>
> Behavioral guidelines to reduce common LLM coding mistakes.
> **Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
