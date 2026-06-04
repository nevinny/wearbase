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

## Architecture

### Public directory

`public_html/` — not the default `public/`. Configured in `composer.json` (`"public-dir": "public_html"`).

### Two firewalls, two User entities

```
/admin  →  firewall: admin   →  Nevinny\AdminCoreBundle\Entity\User
/*      →  firewall: main    →  App\Entity\User
```

Never mix them. Admin routes use `admincore_login`/`admincore_logout`; front-end uses `app_login`/`app_logout`. Admin CRUD controllers extend `AbstractCrudController` from EasyAdmin and are registered in `src/Controller/Admin/DashboardController.php`.

Role hierarchy: `ROLE_BRAND_OWNER > ROLE_BRAND_MANAGER > ROLE_USER > ROLE_CUSTOMER`.

### Two template stacks

| Stack | Base template | Used for |
|---|---|---|
| Bootstrap 5 | `templates/base.html.twig` | Auth, account, checkout, brand LK, cart |
| Tailwind CSS | `templates/tailwind/base.html.twig` | Public pages: `/ru/`, `/ru/brands`, `/ru/brands/{slug}` |

**Do not mix them.** The Tailwind stack has its own language/currency dropdowns using inline JS; the Bootstrap stack uses `templates/components/header.html.twig`.

### Locale & routing

All public pages use `/{_locale}/` as a route prefix with `requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko']`. The locale is resolved in this priority order by `LocaleListener` (priority 20, runs after RouterListener at 32):

1. Cookie `locale`
2. `Accept-Language` header
3. Default language from `language` table
4. Hard fallback: `ru`

To switch language, POST to `/locale/switch` with field `locale=en`. The controller rewrites the locale prefix in the Referer URL so RouterListener picks it up correctly on the next request.

### Currency

All prices are stored in **RUB**. Conversion is done on the fly by `CurrencyConverter` (cached 1h in Symfony Cache). The selected currency is stored in cookie `currency` via `CurrencySession`. Twig globals `app_currency` and `currencies_list` are provided by `CurrencyExtension`.

Use the Twig filter `|price` (registered in `CurrencyExtension`) for displaying prices. Never store converted amounts in DB.

### Database FK constraint: `country.id` is `INT UNSIGNED`

`country.id` is declared `INT UNSIGNED NOT NULL AUTO_INCREMENT`. Any FK column pointing to it **must** also be `INT UNSIGNED NOT NULL`, or MySQL will throw error 3780. `brand.id` and `product.id` are plain `INT` (signed).

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

Весь AI-стек на LLM-сервере 192.168.2.43 (ollama qwen3.5:27b + qwen3-embedding:0.6b, Qdrant :6333, SearXNG :8080, trafilatura). Env в `.env.local`: `LOCAL_LLM_URL/MODEL`, `LOCAL_EMBED_URL/MODEL`, `QDRANT_URL/API_KEY/COLLECTION`, `SEARXNG_URL`, `TRAFILATURA_BIN`, `WORDSTAT_API_KEY`. Флоу: `discover → fetch → embed → generate-content` (статус-машина `brand_rag_pipeline`); подробности и текущие cap'ы — `docs/tasktracker.md`.

| Service | Location | Purpose |
|---|---|---|
| `BrandSourceFinder` | `src/Service/` | Tiered discovery URL-кандидатов (own_site→corpus→mentions), relevance-скоринг. ⚠️ cap'ы по типу живут здесь И в `DiscoverBrandSourcesCommand::CAPS` — менять синхронно |
| `UrlFilter` | `src/Service/` | Единая точка исключений скрейпа: self-домены (wearbase.ru), job-/рекрутинг-хосты (`JOB_NOISE`), env `SCRAPE_EXCLUDED_DOMAINS`; suffix-match, fail-closed |
| `WebScraperService` | `src/Service/` | Fetch + HTML→текст (trafilatura, fallback DomCrawler), кеш 30д |
| `EmbeddingService` / `VectorStoreService` | `src/Service/` | ollama `/api/embed` → Qdrant (коллекция `brand_chunks`, payload brand_id/relevance) |
| `BrandRagService` | `src/Service/` | Retrieve top-k чанков + gate качества (chunks≥3 И score≥0.5, иначе legacy-генерация) |
| `KeywordService` / `WordstatClient` | `src/Service/Keyword/` | Yandex Wordstat `topRequests` → `brand_keyword`; топ-фразы подмешиваются в генерацию (title из топ-фразы) |

### Console commands

| Command | Purpose |
|---|---|
| `app:currency:update-rates` | Fetch rates from CBR XML feed, upsert into `exchange_rate`, clear CurrencyConverter cache |
| `app:brand:stats` | Brand statistics |
| `app:fetch-lamoda-brands` | Scrape Lamoda brand list |
| `app:brand:generate-content` | LLM-описания + SEO meta; RAG-grounded если бренд `embedded` (gate), иначе legacy |
| `app:brand:enrich-contacts` | Обогащение контактами: из локального скрейпа (27b), fallback Perplexity Sonar |
| `app:brand:discover` | RAG-этап 0: поиск URL-источников бренда (SearXNG, tiered) → очередь `brand_source_url` |
| `app:brand:fetch` | RAG-этап 1: дренаж очереди URL (SKIP LOCKED) → trafilatura → `brand_source_document` |
| `app:brand:scrape` | Монолит discover+fetch (legacy fallback по `--id`) |
| `app:brand:embed` | RAG-этап 2: чанки → эмбеддинги (ollama qwen3-embedding) → Qdrant |
| `app:brand:keywords` | Wordstat-ключевики → `brand_keyword` (квота 100/час, авто-пауза/резюм) |
| `app:import-brands` | Bulk brand import |
| `app:migrate-images-to-subdirs` | One-off migration of flat image storage to subdirectory layout |

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
