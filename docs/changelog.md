# Changelog

## 2026-06-12 (этап 1 миграции на Tailwind)

### Added
- **`tailwind/app.html.twig`** — «приложенческий» layout Tailwind-стека для auth/ЛК/корзины/checkout: компактная шапка, минимальный футер, `noindex` по умолчанию.
- **`tailwind/auth/_shell.html.twig`** — переиспользуемый split-panel (брендинг слева, форма справа) для auth-страниц через `{% embed %}`.

### Changed
- **Все 5 auth-страниц переведены с Bootstrap на Tailwind**: login, register, register_brand, forgot_password, reset_password. Дизайн сохранён (тёмная панель + форма), формы/CSRF/Turnstile без изменений. `AuthControllerTest`: селектор ошибки `.alert-danger` → семантический `.form-error`.


## 2026-06-12 (вечер)

### Fixed
- **`/brand-claim/status/{id}` отдавал 500**: контроллер рендерит `brand_claim/status.html.twig`, а файл лежал под старым именем `pending.html.twig` — переименован.

### Changed
- **Шаблоны: Tailwind объявлен основным стеком** (CLAUDE.md обновлён). Канонический шаблон страницы бренда — `tailwind/brand/show.html.twig` (бывший `showv3`).

### Removed
- Мёртвые шаблоны: `tailwind/brand/show.html.twig` (v1), `showv2.html.twig`, `tailwind/index.html.twig` (главная — `hub`), `local-brands/index.html.twig` и `_brand-card.html.twig` (используются `az-index` + `-compact`), `sitemap/sitemap.xml.twig` (используется `xml.html.twig`); `file.html` (870KB) из корня репозитория.


## 2026-06-12

### Added
- **Подписка на новости (double opt-in)**: сущность `NewsletterSubscriber` + миграция `Version20260612_newsletter_subscriber`; `NewsletterController` (`POST /newsletter/subscribe`, `GET /newsletter/confirm/{token}`, `GET /newsletter/unsubscribe/{token}` — отписка soft-delete); письма `emails/newsletter_confirm` и `emails/newsletter_digest` (с обязательной ссылкой отписки); команда `app:newsletter:send-digest` (новые бренды + скидки, `--dry-run`, крон еженедельно). Формы в обоих футерах переведены с `landing_lead` на новый роут — раньше «подписка» складывала email в лиды без рассылки и без отписки.
- **Публикация блога**: `app:blog:publish-drafts` — идемпотентная публикация статей из `_docs/blog-drafts/*.html` по манифесту (4 статьи; контент синхронизирован с локальной БД). Чинит 404 на `/ru/blog/komissii-marketpleysov-2026` на проде: статьи были опубликованы только локально.


## 2026-05-14

### Fixed
- `showv2.html.twig:259` — исправлен доступ к BrandLink: `l.url` → `l.linkUrl`
- `links.html.twig` — исправлен доступ к BrandLink: `link.url` → `link.linkUrl`

### Updated

**Meta validation limits** (согласно SEO Guide):
- `ContentValidator.php`: `MAX_META_DESCRIPTION` 155 → 160 → 155
- `ContentValidator.php`: `MIN_DESCRIPTION_WORDS` 200 → 170
- `LlmService.php`: промпты description 150-155 символов, заканчивать на целом слове
- `LlmService.php`: fallback mb_substr description 160 → 155

**GenerateBrandContentCommand.php:**
- Добавлен retry для meta (до 3 попыток) при неудачной валидации
- Жёсткая обрезка: title 60, description 155, keywords 200 символов

### Context

SEO Guide рекомендует:
- Title: ≤60 символов
- Description: 140-160 символов (оптимально 150-155, чтобы Google не обрезал на середине слова)
- Description должно заканчиваться на целом слове
- FRES target: 60-70%

---

## 2026-05-13

### Added
- `LlmService.php` — OpenRouter API интеграция (Claude Haiku)
- `ContentValidator.php` — AI-phrase detection (21 фраза), word count validation
- `GenerateBrandContentCommand.php` — генерация description + meta для брендов
- `CheckBrandContentCommand.php` — проверка качества контента с JSON экспортом
- `docs/tasktracker.md` — план имплементации

### Meta Fields
- `Brand.php`: добавлены `metaTitle`, `metaDescription`, `metaKeywords`

### SEO Infrastructure
- `SitemapController.php` — `/sitemap.xml`
- `robots.txt` — sitemap, disallow /admin
- Hub page `/ru/` — Organization schema, BreadcrumbList
- `OgImageController.php` — SVG og:image
- Schema.org: Organization, BreadcrumbList, WebSite
- hreflang в `base.html.twig`
- og:title/description, twitter:card

### Templates
- `tailwind/base.html.twig` — SEO meta, hreflang, og:image, canonical
- `tailwind/hub.html.twig` — Hub page с Schema.org
- `tailwind/brand/showv2.html.twig` — brand page, similar brands
- `tailwind/brand/index.html.twig` — каталог с SEO meta

### Repository
- `BrandRepository.php`: `findSimilarBrands()`, `findFeaturedBrands()`

### Commands
```bash
# Генерация meta для всех брендов с описанием
php bin/console app:brand:generate-content --meta-only 500

# Генерация description для брендов без описания
php bin/console app:brand:generate-content 100

# Конкретный бренд
php bin/console app:brand:generate-content --id=148

# Проверка контента
php bin/console app:brand:check-content --limit=100

# Экспорт проблем
php bin/console app:brand:check-content --limit=500 --export=/tmp/issues.json
```