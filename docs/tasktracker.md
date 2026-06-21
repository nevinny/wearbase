# WEARBASE SEO Implementation Plan

**Обновлено:** 2026-05-14
**Статус:** В РАБОТЕ

---

## Цель
Применить SEO Guide 2026 к wearbase.ru — масштабируемый контент для брендов через LLM.

---

## Ограничения
- OpenRouter API (Claude Haiku) — заменил локальный Ollama
- Бренды зависят от локали (RU/EN)
- Без content manager — всё через LLM
- Начать с RU, skip homepage и переводы

---

## Что сделано

### 1. Инфраструктура LLM
- [x] OpenRouter интеграция (LlmService.php)
- [x] API key в .env.local
- [x] Модель: anthropic/claude-3.5-haiku

### 2. Генерация контента
- [x] GenerateBrandContentCommand — description + meta
- [x] Meta из существующего description (вариант B)
- [x] Meta fields в Brand entity (metaTitle, metaDescription, metaKeywords)
- [x] Миграция для meta полей
- [x] Опция --id для конкретного бренда
- [x] Опция --meta-only для брендов с описанием
- [x] Retry logic (до 3 попыток) для meta генерации

### 3. Валидация контента
- [x] ContentValidator.php — 21 AI-фраза
- [x] Проверка: description (170+ слов), meta (title ≤60, desc ≤155 символов), без URL
- [x] CheckBrandContentCommand — массовая проверка
- [x] Экспорт в JSON

### 4. SEO структура
- [x] Hub страница /ru/ — Organization schema, BreadcrumbList
- [x] Каталог /ru/brands/ — meta, schema, breadcrumbs
- [x] Страница бренда — og:title/description, BreadcrumbList
- [x] SitemapController — все URL
- [x] robots.txt — sitemap, disallow admin
- [x] hreflang в base.html.twig
- [x] OG image /og-image.svg

### 5. Репозиторий
- [x] findSimilarBrands() — по городу и стилям
- [x] findFeaturedBrands() — с описанием 100+ символов

### 6. Шаблоны
- [x] hub.html.twig — популярные бренды, города, стили
- [x] brand/index.html.twig — SEO meta
- [x] brand/showv2.html.twig — similar brands, og:image (исправлен доступ к BrandLink)
- [x] base.html.twig — hreflang, og:image, canonical

### 7. Документация
- [x] docs/changelog.md — история изменений
- [x] docs/seo_rules.md — правила и принципы SEO
- [x] docs/seo_tools.md — список инструментов

---

## Аудит 2026-05-14

### ✅ Проверено и работает
| Проверка | Статус |
|----------|--------|
| robots.txt | ✓ |
| sitemap.xml | ✓ |
| Schema.org (Organization, BreadcrumbList, WebSite) | ✓ |
| Canonical | ✓ нет mismatch |
| H1 | ✓ по одному на страницу |
| Brand Schema | ✓ |

### ⚠️ Проблемы (приоритеты)

#### КРИТИЧНО — Исправить в первую очередь
- [ ] **Hreflang** — 345 страниц без self-referencing hreflang
  - Каждая страница бренда должна содержать `<link rel="alternate" hreflang="x-default" href="https://wearbase.ru/ru/brands/{slug}">`
  - Проверить bidirectional: `/ru/brands/{slug}` → `/ru/`, но `/ru/` не возвращает обратно
  - Файл: `templates/tailwind/base.html.twig`

- [ ] **HTTP→HTTPS** — отсутствует HSTS header, TTFB 911ms (цель <600ms)
  - Настроить на уровне сервера (nginx/cloudflare)

#### СРЕДНЕ — Второй приоритет
- [ ] **Контент брендов** — 8/10 с <170 словами
  - Запустить перегенерацию для брендов с короткими описаниями
  - 22 бренда без описания

- [ ] **Meta description** — 1 бренд без meta_description
  - Команда: `php bin/console app:brand:generate-content --meta-only 500`

---

## Что нужно сделать

### Фаза 1: Исправления аудита
- [ ] Исправить hreflang в base.html.twig (self-reference + bidirectional)
- [ ] Настроить HSTS + оптимизировать TTFB
- [ ] Перегенерировать description для брендов <170 слов
- [ ] Сгенерировать meta для всех брендов с описанием

### Фаза 2: Парсинг данных (СОГЛАСНО SEO Guide)
- [ ] Создать парсер VK API для сбора данных о брендах
- [ ] Парсить официальные сайты брендов
- [ ] Хранить собранные данные в отдельной таблице

### Фаза 3: RAG pipeline (СОГЛАСНО SEO Guide)
- [ ] RAG lookup перед генерацией
- [ ] Multi-source context (VK + website + каталог)
- [ ] Self-heal с retry (max 3, temperature decay)

### Фаза 4: Self-heal
- [ ] Интегрировать checklist.py логику
- [ ] Автоматический retry при fail валидации
- [ ] Reject после 3 fails

### Фаза 5: Anti-ban меры
- [ ] Лимиты: max 5 статей/день (из SEO Guide)
- [ ] Content velocity pacing
- [ ] Author rotation (если будут авторы)

### Фаза 6: Мониторинг
- [ ] Интеграция с GSC API
- [ ] Отслеживание indexed/not-indexed
- [ ] Alert при проблемах

---

## Команды

```bash
# Генерация meta для всех брендов с описанием
php bin/console app:brand:generate-content --meta-only 500

# Генерация description для брендов без
php bin/console app:brand:generate-content 100

# Конкретный бренд
php bin/console app:brand:generate-content --id=148

# Проверка контента
php bin/console app:brand:check-content --limit=100

# Экспорт проблем
php bin/console app:brand:check-content --limit=500 --export=/tmp/issues.json
```

---

## Ключевые решения

### Локализация
- Hybrid (C): часть брендов только RU, часть переводить позже
- Locale-specific slugs: /ru/brands/xxx vs /en/brands/xxx

### Контент
- Generic генерация → Unique per brand (через RAG)
- Focus RU first, expand later

### AI phrases (бан)
```
инновационный, уникальный, передовой, лидирующий, новаторский,
выделяется, отличается, несравненный, беспрецедентный
```

---

## Документы
- SEO Guide: `/Volumes/SAMSUNG-origin/Users/zyablik/Downloads/SEO_GUIDE_2026-04-17/`
- wearbase: `/Volumes/SAMSUNG-origin/Users/zyablik/work/wearbase/`

---

## Ссылки
- LLM Service: `src/Service/LlmService.php`
- Content Validator: `src/Service/ContentValidator.php`
- Generate Command: `src/Command/GenerateBrandContentCommand.php`
- Check Command: `src/Command/CheckBrandContentCommand.php`
- Hub Template: `templates/tailwind/hub.html.twig`
- Base Template: `templates/tailwind/base.html.twig`

---

# Страница товара — редизайн (по WB)

**Обновлено:** 2026-05-24
**Статус:** ПЛАНИРОВАНИЕ

## Цель
Довести страницу товара до уровня WB: галерея, цвета, размеры, скидка, количество, характеристики.

## Текущее состояние

| Компонент | Сейчас | Цель (как WB) |
|---|---|---|
| Галерея | 1 фото + превью, без зума | Слайдер, зум, клик по превью меняет главное |
| Цвет | Нет | Свотчи (colorHex), привязка вариантов к цвету |
| Размер | Кнопки без stock info | Индикация остатка (зелёный/серый) |
| Цена | minPrice|price только | `~~старая~~ -X% **новая**` динамически |
| Количество | Нет | `− 1 +` с max=stockQty |
| Характеристики | Нет полей | material, composition, care, country, manufacturer |
| Доставка | Нет | Сроки/стоимость из ShippingRule |
| Похожие товары | Нет | По категории/стилю |
| Бренд на странице | Только ссылка | Блок с логотипом + описание + ссылки |
| Отзывы | Нет | Отдельная задача |
| Избранное | Нет | Отдельная задача |

---

## Что сделано

### Фаза 0 — Основа
- [x] UUID-роутинг `/product/{uuid}`
- [x] AJAX добавление в корзину
- [x] Переключатель размера (JS)
- [x] ProductImportService — 13 колонок

---

## Что нужно сделать

### Фаза 1 — UI без новых сущностей (только шаблон + JS)
- [ ] **Галерея с зумом** — клик по превью → смена main, zoom при наведении
- [ ] **Свотчи цвета** — варианты группируются по color+colorHex, клик меняет доступные размеры/цену/фото
- [ ] **Цена со скидкой** — при выборе варианта показывать его цену, comparePrice, % скидки
- [ ] **Селектор количества** — `−` / `+` кнопки, max из stockQty, передача qty в cart_add
- [ ] **Блок бренда** — логотип, anons, ссылки на соцсети
- [ ] **Похожие товары** — `ProductRepository::findSimilar()`, сетка карточек внизу

**Затрагиваемые файлы:**
- `templates/catalog/show.html.twig` — все UI изменения
- `src/Controller/Catalog/CatalogController.php` — передать brand, similar products, shipping
- `src/Repository/ProductRepository.php` — новый метод findSimilar()

### Фаза 2 — Характеристики (новые поля + миграция)
- [ ] Добавить поля в `Product`: material, composition, careInstructions, countryOfOrigin, manufacturer
- [ ] Миграция БД
- [ ] Вывести таблицу характеристик на странице
- [ ] Добавить колонки 13-17 в `ProductImportService`
- [ ] Обновить XLSX-шаблон

**Затрагиваемые файлы:**
- `src/Entity/Product.php` — новые поля + getter/setter
- `src/Service/ProductImportService.php` — новые константы + обработка
- `public/downloads/wearbase-import-template.xlsx` — новые колонки

### Фаза 3 — Изображения в импорте (критично для пакетной загрузки)
- [ ] Колонка 18 «Фото (URL)» в импорте — несколько URL через `|`
- [ ] `ImageDownloaderService` — скачивание изображений из URL
- [ ] Привязка фото к варианту (через `ProductImage.variant`)
- [ ] Обновить XLSX-шаблон

**Затрагиваемые файлы:**
- `src/Service/ProductImportService.php` — новая логика
- `src/Service/ImageDownloaderService.php` — новый сервис
- `src/Entity/ProductImage.php` — готово (variant relation уже есть)

---

## Команды

```bash
# Создать миграцию для новых полей Product (Фаза 2)
php bin/console make:migration

# Запустить миграцию
php bin/console doctrine:migrations:migrate

# Сидирование тестовых товаров
php bin/console app:seed:test-products

# Импорт товаров из XLSX
# /brand/products/import (веб-интерфейс)
```

---

## Ключевые решения

### Цвета
- Варианты группируются по `color` в JS (не на сервере) — данных достаточно
- Первый свотч = первый цвет в коллекции вариантов
- При клике на свотч показываем размеры только этого цвета

### Характеристики
- Хранить в `Product` (не отдельная таблица) — не нужно размножать сущности
- Опциональные поля — не блокируют создание товара

### Изображения в импорте
- Использовать `Symfony HttpClient` + `filesystem` для скачивания
- Максимум 10 фото на товар
- Если фото не скачалось — пропустить (не блокировать импорт)

### Похожие товары
- По категории (если есть) + по стилям (если есть)
- Исключать текущий товар
- Лимит 8
- Сортировка: сначала из того же бренда

---

## Ссылки
- Product Controller: `src/Controller/Catalog/CatalogController.php`
- Product Show Template: `templates/catalog/show.html.twig`
- Product Entity: `src/Entity/Product.php`
- ProductVariant Entity: `src/Entity/ProductVariant.php`
- ProductImage Entity: `src/Entity/ProductImage.php`
- Import Service: `src/Service/ProductImportService.php`
- Seed Command: `src/Command/SeedTestProductsCommand.php`

---

# Инфраструктура — Корзина и сессии

**Обновлено:** 2026-05-24
**Статус:** ОЧЕРЕДЬ

## Проблемы

### 1. Нет иконки корзины в tailwind-хедерах
Страницы каталога (`catalog/show.html.twig`, `catalog/index.html.twig`) наследуют `templates/tailwind/base.html.twig` — у него **свой хедер** без корзины. Иконка с `fas fa-shopping-bag` есть только в Bootstrap-версии (`templates/components/header.html.twig`).

**Решение:**
- [ ] Добавить SVG-иконку корзины + бейдж счётчика в `templates/tailwind/base.html.twig` (рядом с переключателем валюты, строка ~159)
- [ ] Перенести JS обновления бейджа из `components/header.html.twig` (строки 205-229) в tailwind-базу

### 2. Гостевая корзина сбрасывается при закрытии браузера
`config/packages/framework.yaml`: `session: true` — без параметров. По умолчанию `cookie_lifetime = 0` (до закрытия браузера). Гостевая корзина привязана к `sessionId` — после переоткрытия sessionId новый, корзина пустая.

**Решение:**
- [ ] Настроить cookie_lifetime и gc_maxlifetime в `config/packages/framework.yaml`:
```yaml
session:
    enabled: true
    cookie_lifetime: 2592000  # 30 дней
    gc_maxlifetime: 2592000   # 30 дней
```

---

## Затрагиваемые файлы
- `templates/tailwind/base.html.twig` — добавить иконку + бейдж + JS
- `templates/components/header.html.twig` — JS можно удалить (перенесён в tailwind-базу)
- `config/packages/framework.yaml` — session cookie_lifetime

## Команды для проверки
```bash
# Проверить cookie после настройки
php bin/console debug:container --parameters | grep session
```

---

# Футер — перенос в tailwind

**Обновлено:** 2026-05-24
**Статус:** ГОТОВО

## Что сделано

- [x] Богатый футер (4 колонки: помощь, о компании, бренды, подписка + соцсети + юрлинки) из `components/footer.html.twig` перенесён в `templates/tailwind/base.html.twig` в tailwind-стилях
- [x] Исправлен хардкод `2025` → динамический `{{ "now"|date("Y") }}` в Bootstrap-футере
- [x] Вместо FontAwesome — встроенные SVG-иконки соцсетей (Instagram, Facebook, TikTok, Pinterest)
- [x] Часть ссылок ведут на реальные маршруты (`brand_index`, `catalog_index`), остальные — `#`

## Затрагиваемые файлы
- `templates/tailwind/base.html.twig` — заменён футер
- `templates/components/footer.html.twig` — поправлен год

---

# Бренд-специфичные тарифы доставки

**Обновлено:** 2026-05-24
**Статус:** ПЛАНИРОВАНИЕ

## Цель
Дать каждому бренду возможность устанавливать собственные условия доставки (цена, срок, бесплатно от) для каждого перевозчика в ЛК Бренда.

## Подход
Отдельная сущность `BrandShippingRule` (новая таблица `brand_shipping_rule`). Global `ShippingRule` остаётся неизменным. В чекауте приоритет: `BrandShippingRule` → `ShippingRule` fallback.

## Что нужно сделать

### 1. Entity + Migration
- [ ] Создать `BrandShippingRule` — поля: `id`, `brand` (ManyToOne NOT NULL), `country` (ManyToOne), `carrier`, `name`, `priceRub`, `daysMin`, `daysMax`, `maxWeightKg`, `freeFromRub`, `trackingUrl`, `isActive`, `sortOrder`
- [ ] Миграция: `CREATE TABLE brand_shipping_rule`

### 2. Repository
- [ ] `BrandShippingRuleRepository` — `findForBrand(Brand)`, `findForBrandAndCountry(Brand, Country)`

### 3. Admin (EasyAdmin)
- [ ] `BrandShippingRuleCrudController` — CRUD для админов

### 4. Brand Cabinet (ЛК Бренда)
- [ ] `BrandShippingRuleFormType` — форма (carrier choice, country, price, days, free_from)
- [ ] `BrandShippingController` — роут `/brand/shipping`, extends `BrandDashboardController`
- [ ] `templates/brand_lk/shipping.html.twig` — таблица правил + кнопка «+ Добавить», у каждой строки Edit/Delete
- [ ] `templates/brand_lk/layout.html.twig` — пункт «Доставка» в sidebar

### 5. Checkout
- [ ] В `CheckoutController::shippingRules()` и `resolveShippingCost()` — при наличии бренда в заказе сначала искать `BrandShippingRule`, потом `ShippingRule`

### 6. Public delivery page
- [ ] В карточки перевозчиков на `/ru/delivery` добавить примечание: бренды могут устанавливать собственные тарифы

## UX в ЛК
- Пустой список = бренд не настроил доставку → в чекауте берутся глобальные правила (`ShippingRule`)
- Добавление: выбор перевозчика → цена → срок min/max → порог бесплатной доставки
- Только включённые перевозчики (бренд решает, какие активны)

## Затрагиваемые файлы
- `src/Entity/BrandShippingRule.php` — новая сущность
- `src/Repository/BrandShippingRuleRepository.php` — новый репозиторий
- `src/Controller/Admin/BrandShippingRuleCrudController.php` — новый админ CRUD
- `src/Controller/BrandLk/BrandShippingController.php` — новый контроллер ЛК
- `src/Form/BrandLk/BrandShippingRuleFormType.php` — новая форма
- `templates/brand_lk/shipping.html.twig` — новый шаблон
- `templates/brand_lk/layout.html.twig` — sidebar
- `src/Controller/Cart/CheckoutController.php` — приоритет BrandShippingRule
- `templates/pages/delivery.html.twig` — примечание о брендовых тарифах

## Команды
```bash
# Создать миграцию
php bin/console make:migration

# Запустить миграцию
php bin/console doctrine:migrations:migrate
```

---

# Платёжные провайдеры — backlog интеграций

**Обновлено:** 2026-06-03
**Статус:** В РАБОТЕ (подключаем постепенно)

## Архитектура (готова)
Каждый провайдер = реализация `App\Payment\Gateway\PaymentGatewayInterface`
(`createRedirectPayment` + `fetchStatus` + нормализация статуса), регистрируется
тегом `app.payment_gateway` (резолв через `PaymentGatewayRegistry`), плюс строка
в каталоге `payment_provider`. Реквизиты/секрет/доп.поля — в `SellerPaymentAccount`
(`account_ref`, `secret_encrypted`, `config` JSON), секрет шифрует `SecretCipher`.

## Definition of Ready (для каждой интеграции)
- [ ] Gateway-адаптер: `createRedirectPayment` + `fetchStatus` + нормализация статуса в `succeeded|canceled|pending|failed`
- [ ] Вебхук-роут провайдера (парсинг + **верификация подписи**) — отдельно от yookassa
- [ ] Sandbox-прогон с тестовыми кредами → только потом пометка `live`
- [ ] Строка в `payment_provider` (`is_active`, `supports_direct`/`supports_marketplace`)
- [ ] Расширить `SellerPaymentAccount::isReadyToAcceptOnline()` на код провайдера
- [ ] Чек по 54-ФЗ (для РФ): кто и как пробивает (продавец / провайдер за продавца)

**Легенда:** `[x]` live · `[~]` адаптер написан, sandbox-unverified, `is_active=0` · `[ ]` не начато

## Россия — приоритет 1
- [x] **ЮKassa** — REST (SDK `yoomoney/yookassa-sdk-php`), сплит, СБП. ЭТАЛОН (`YooKassaGateway`).
- [~] **Т-Бизнес (Тинькофф Эквайринг)** — Merchant API v2 (Init/GetState). `TinkoffGateway`, нужен sandbox.
- [~] **CloudPayments** — REST (orders/create, payments/find). `CloudPaymentsGateway`, нужен sandbox + вебхук.
- [~] **Сбер (эквайринг)** — REST (register.do / getOrderStatusExtended.do). `SberGateway`, нужен sandbox (3dsec.sberbank.ru) + вебхук. Креды: accountRef=userName, secret=password.
- [~] **Robokassa** — подписанный redirect + OpStateExt. `RobokassaGateway`, нужен sandbox + вебхук ResultURL (ответ `OK{InvId}`). Креды: accountRef=MerchantLogin, secret=зашифр. JSON `{p1,p2}`. TODO: InvId привязать к id заказа (сейчас crc32).
- [~] **PayKeeper** — JSON API на поддомене мерчанта (token + Basic auth, invoice/preview). `PaykeeperGateway`; sandbox+вебхук. Креды: accountRef=login, secret=password, `config.base_url`=поддомен.
- [~] **Payselection** — REST hosted page, HMAC-SHA256. `PayselectionGateway`; сверить подпись/ответ по доке+SDK, sandbox+вебхук. Креды: accountRef=SiteId, secret=SecretKey.
- [ ] **Best2Pay** — REST, поддержка маркетплейс-расчётов.
- [ ] **Альфа-Банк интернет-эквайринг** — REST.
- [ ] **Prodamus / PayMaster** — для самозанятых и мелких брендов.
- [ ] **СБП напрямую (НСПК c2b/QR)** — не отдельный шлюз; обычно метод внутри эквайрера. Оценить прямую интеграцию через банк-партнёра.

## Казахстан (KZT) — приоритет 2
- [ ] **Halyk Epay (Halyk Bank)** — JS `halyk.pay()` + серверный API. ~65% магазинов KZ.
- [ ] **Kaspi Pay** — крупнейший (QR / по номеру телефона). Офиц. мерчант-API ограничен — уточнить путь (Kaspi Business / партнёрские ApiPay.kz, AiPay).
- [ ] **ioka** — современный REST-эквайринг.
- [ ] **CloudPayments KZ** — тот же адаптер, отдельный контур/креды.
- [ ] **Alipay+ через Kaspi QR** — кросс-бордер (туристы/диаспора).

## Беларусь (BYN) — приоритет 3
- [ ] **bePaid** — REST: карты Visa/MC/Мир + ЕРИП + Apple/Google Pay.
- [ ] **WebPay** — Visa/MC/БЕЛКАРТ + ЕРИП.
- [ ] **ЕРИП (АИС «Расчёт»)** — локальная рельса, обычно через bePaid/WebPay (не отдельный адаптер).

## ОАЭ (AED) — приоритет 3
- [ ] **Telr** — мультивалюта (120+), payment links, REST.
- [ ] **PayTabs** — GCC-провайдер, REST, подписки.
- [ ] **Checkout.com** — масштаб, локальные + международные методы.
- [ ] **Network International (N-Genius)** — энтерпрайз, банки ОАЭ.
- [ ] **Amazon Payment Services (ex-PayFort)** — рассрочка, POS.

## Турция (TRY) — приоритет 4
- [ ] **iyzico** — самый популярный, REST + SDK.
- [ ] **PayTR** — локальное покрытие, virtual POS.
- [ ] **Param** — финтех (кошелёк + POS + gateway).
- [ ] **Sipay** — мультивалюта для международных продаж.

## Китай (CNY) — приоритет 4
- [ ] **Alipay (Cross-border / Global)** — доминирующий метод.
- [ ] **WeChat Pay** — доминирующий метод.
- [ ] **UnionPay** — карты + унифицированный QR (связка с Alipay/WeChat).
- [ ] **Кросс-бордер агрегатор (Asiabill / AsiaPay / Worldline China)** — единая точка для всех CN-методов (проще, чем 3 прямые интеграции).

## Международные карты (любой рынок без локального провайдера)
- [ ] **Stripe** — глобальные карты, dev-friendly API.

## Источники исследования (2026)
- РФ-эквайринг/агрегаторы: online-check.business.ru, digitalkassa.ru, payanyway.ru
- Казахстан: epayment.kz (Halyk Epay), ir.kaspi.kz, apipay.kz
- ОАЭ: scaleupally.io, skimbox.co, xstak.com
- Турция: paymentgateways.org, finqfy.com
- Китай: statrys.com, unionpayintl.com
- Беларусь: bepaid.by, webpay.by, raschet.by

---

# RAG-пайплайн локальной генерации контента (обновлено 2026-06-03)

Заземление описаний/meta на реальные веб-данные брендов (вместо галлюцинаций) +
замена платного Perplexity локальным скрейпом. AI-стек на GPU-сервере 192.168.2.43
(ollama qwen3.5:27b + qwen3-embedding:0.6b, Qdrant, SearXNG, trafilatura).

## Что сделано (коммиты 0a99aad, 31b1599, 38ab7df, a48bace, 00d4ca0)
- [x] Инфра на сервере: Qdrant (:6333), SearXNG (:8080, JSON вкл.), bge-m3→qwen3-embedding:0.6b, trafilatura (pipx)
- [x] Схема: `brand_rag_pipeline` (статус-машина), `brand_source_document`, `brand_keyword`
- [x] `app:brand:scrape` — corpus-discovery (SearXNG, до 50, маркетплейсы вкл., искл. wearbase.ru + russianstreetwear.club), trafilatura/DomCrawler, per-URL кеш (TTL 30д)
- [x] `app:brand:embed` — чанки → Qdrant (только изменённые)
- [x] `app:brand:generate-content` — RAG multi-aspect + gate (chunks≥3 И score≥0.5), EM-recovery, шардинг
- [x] `app:brand:enrich-contacts` — контакты из локального скрейпа (Perplexity-fallback)
- [x] `app:brand:keywords` — Wordstat → brand_keyword (origin/related + monthly_shows), заранее
- [x] Все стадии: `--shard/--total`, `--dry-run`, `--force`
- [x] **Память в долгих батчах**: per-brand `flush/clear` + `gc_collect_cycles()` в цикле; долгие прогоны — ТОЛЬКО с `--no-debug` (dev-профайлер Doctrine копит SQL+backtrace → OOM на ~750 брендах при 512M, keywords-прогон 2026-06-04)

## Discovery-split — СДЕЛАН (коммиты 81a3553, 948ce41, d8b3cae)
- [x] `app:brand:discover` (лёгкий, только SearXNG) наполняет очередь `brand_source_url` (дедуп по url_hash, claim через SKIP LOCKED) + ставит `has_own_site`; `app:brand:fetch` (тяжёлый, trafilatura) дренит очередь → `brand_source_document`. Монолит `app:brand:scrape` оставлен fallback'ом по `--id`. Дизайн — раздел «Архитектура: Discovery → URL-очередь → Fetch».
- [x] **Подняты per-type cap'ы** (948ce41): было ~10 URL/бренд — резали cap'ы в ДВУХ слоях (BrandSourceFinder tier-cap'ы И DiscoverBrandSourcesCommand::CAPS поверх на enqueue, менять синхронно!). Теперь own_site 2, marketplace 5, catalog 6, article_review 5, social 6, mention 6 ≈ потолок 30/бренд; MAX_PER_HOST 4→5, PER_QUERY 20→25; +запросы «{бренд} интернет-магазин / бренд / бренд одежды». Телодвижения: 11→15. Niche-бренды остаются низкими (zyablik=6) — ограничены реальной выдачей SearXNG, не cap'ами; форсить выше = тянуть мусор.
- [x] **Инцидент SearXNG-ban 2026-06-03/04** (ac3cecc): ночной discover (3 процесса с `--shard` но БЕЗ `--total` → шардинг выключен, одни и те же бренды × 3, ~8 запросов/бренд без пауз) забанил все движки SearXNG (Google/Startpage CAPTCHA, brave/wiki too-many-requests) → тиры пустые, 3896 брендов «сожжены» (discovered с 1-5 slug-guess URL, навсегда вне переобхода). Починка: discovered_at сброшен (31 здоровый с ≥6 URL оставлен). Защита: SearxClient кидает `SearxUnavailableException` (вместо молчаливого []), discover не помечает бренд при лежащем поиске + стоп после 3 подряд (circuit breaker), `searchPaced()` — пауза 1.5с между запросами. Уроки: `--shard` всегда с `--total`; массовый discovery — через `app:rag:daemon`, не руками.
- [x] **`app:rag:daemon`** (9375964): оркестратор discover→fetch→embed→generate, каждая стадия — дочерний PHP-процесс с `--no-debug` (память умирает с ребёнком — OOM-класс снят), flock от второго экземпляра, `--once`/`--stages=discover:5,embed:10`. keywords в демон не входит (своя квота/троттлинг). Времянка до Messenger.
- [x] **Job-/рекрутинг-хосты исключены** (d8b3cae): hh.ru, superjob, rabota.ru, jobfilter, dreamjob, trud.com и др. упоминают бренд как РАБОТОДАТЕЛЯ (вакансии/зарплаты), проходили co-occurrence фильтр («{бренд} магазин одежды» в тексте вакансии) → шум в корпусе. Список `UrlFilter::JOB_NOISE`, suffix-match ловит поддомены (saratov.jobfilter.ru). Старые job-строки из очереди вычищены.

## Wordstat — АКТИВИРОВАН (коммит 062db00)
- [x] Реальный API выяснен эмпирически: `POST /v2/wordstat/topRequests`, `Authorization: Api-Key`, **folderId НЕ нужен**. Ответ: results(origin)+associations(related), count=показов/мес.
- [x] `WordstatClient` под реальный контракт; `WORDSTAT_API_KEY` в .env.local
- [x] Фильтр релевантности (имя бренда ИЛИ fashion-термин) — чинит мусор омонимов (SYNOPTIC→синупрет отсеяно)
- [x] Проверено: 185 ключевиков на 8 брендов
- [ ] (опц.) использовать ещё `regions` (affinity>130 — региональный спрос) и `dynamics` (тренды) из того же API

### Использование богатого набора ключевиков в SEO
`brand_keyword` хранит до 100 фраз с частотой (`monthly_shows`) и типом (origin/related). meta keywords тег Google/Яндекс игнорят — ценность в естественном вхождении в контент/title.
- [x] **A. Заземление генерации на частотные запросы** — топ origin-фразы по частоте в промпт описания: «вплети естественно целевые запросы, без переспама». (коммит daf35ce)
- [x] **B. Title/H1 из топ-фразы** — самая частотная релевантная фраза → primary keyword в title. (коммит daf35ce)
- [ ] **C. FAQ-блоки из длинного хвоста + FAQPage schema** — низкочастотные/вопросные фразы → генерить FAQ + микроразметка (расширенные сниппеты).
- [ ] **D. Кластеры ключевиков → теговые/фасетные посадочные** — группировка фраз по подтемам («{бренд} куртка/зима/oversize») → отдельные страницы или внутренние анкоры.
- [ ] (риск) переспам: промпт обязан требовать ЕСТЕСТВЕННОЕ вхождение (SpamBrain/E-E-A-T) — не стаффинг.

## НЕ СДЕЛАНО / TODO
- [ ] **Re-discovery тонких после возвращения Google**: с 2026-06-04 ~08:00 UTC discovery едет на bing-only (Google капчит IP сервера после ночного обстрела) → niche-бренды помечаются discovered с 3-4 URL. Когда Google оживёт (~сутки-двое) — повторить сброс: `UPDATE brand_rag_pipeline p LEFT JOIN (SELECT brand_id, COUNT(*) c FROM brand_source_url GROUP BY brand_id) u ON u.brand_id=p.brand_id SET p.discovered_at=NULL WHERE p.discovered_at >= '2026-06-04 07:50:00' AND COALESCE(u.c,0) <= 5` — дедуп сделает дооткрытие дёшевым. Долгосрочно решается TTL-refresh'ем (см. «Оркестрация/cron»).
- [x] **SEO-автоматизация на сервере — ПОЛНАЯ** (2026-06-04, 0b12e0b): на 192.168.2.43 крутятся 3 демона (discover/fetch · embed/generate/faq · keywords — Mac больше не нужен, только MySQL) + cron `app:gsc:sync --report` 06:00 (Telegram-алерты через AdminNotifier). Внедрено по анализу: когортная метрика «опубликовано 14д+ → % в индексе» (отчёт+дашборд), drip-health тормоз в publish-tick (когорта 7-21д: indexed<10%→×0.25, <30%→×0.5; ТОЛЬКО замедление, fail-open), RATE_START 5→3. ⚠️ После rsync новых КОМАНД на сервер — обязательно `cache:clear --no-debug` (stale no-debug контейнер не видит их).
- [ ] **Аналитика тарифа «Премиум» НЕ СУЩЕСТВУЕТ** (обнаружено 2026-06-04): tariff.has_analytics=1 продаётся за 3000₽/мес, лендинги обещают «Аналитика продаж», но hasAnalytics не используется нигде кроме entity. Дизайн от агента-архитектора — раздел «Архитектура: аналитика бренда + GSC» (ниже). Включает подключение Google Search Console (и для платформы — мониторинг индексации при дрип-публикации, Фаза 6).
- [x] **Скрытие неготовых брендов** (2026-06-04): active остались только готовые (description+meta, 438 шт ≤ 500, вкл. заявленный zyablik) — каталог/sitemap сжались до них; 5788 → status='new' + publish_pending=1, ждут пайплайн (генерация→FAQ→пуш на прод→дрип-публикация). Заявленных/с товарами среди скрытых нет (проверено).
- [ ] **Физические магазины бренда (brand_store) — вывести и дать управлять**: данные есть (1449 точек у 744 брендов из enrichment, source='enrichment'), но «спящие» — нигде не отображаются и не редактируются. Сделать: (а) ✅ блок «Магазины» на showv2 + ClothingStore JSON-LD (2026-06-04); (б) ✅ CRUD в кабинете бренда (/brand/stores, 2026-06-04: добавление/правка/удаление; owner-правка ставит provenance=owner на datapoint'ы — закрепляет от ре-обогащения и голосов; «авто»-точку достаточно открыть и сохранить); (в) CRUD в админке (EasyAdmin); (г) включить stores в payload агент-пуша на прод; (д) валидация enrichment-точек (омонимы — кейс Zatmenie: адрес ночного клуба). FAQ уже использует адреса как факты (2026-06-04).
- [ ] **Боевой прогон scrape на сервере** (там trafilatura): задать `TRAFILATURA_BIN` в серверном `.env.local`; на Mac сейчас fallback DomCrawler
- [ ] **Реальный батч**: прогнать N брендов без `--dry-run`, глазами проверить качество grounded-описаний, потом масштабировать шардами
- [ ] **Очередь + воркеры на LLM-сервере** (приоритет): скрейпер ДОЛЖЕН запускаться на сервере 192.168.2.43 (там trafilatura/ollama/Qdrant/SearXNG). Воркер ходит в очередь, берёт задания, выполняет, отдаёт результаты. Заменяет ручной `--shard/--total`. Архитектуру (Symfony Messenger / транспорт / топология воркеров / доступ к БД / деплой systemd-консьюмеров) — продумать отдельно (см. ниже «Архитектура очереди»)
- [ ] **Оркестрация/cron** боевого флоу: порядок scrape→keywords→embed→generate, расписание, периодический refresh (re-обход по TTL — сейчас findForScrape берёт только pending/failed)
- [x] **Perplexity-ветка удалена** (bce3cd2, 2026-06-04): researchBrandContacts вырезан; enrich без корпуса пропускает бренд до fetch
- [x] **PHPUnit-тесты** (a6c9633, 2026-06-04): 23 теста — UrlFilter, TextChunker (регрессия хвоста), WordstatClient (квота), SearxClient (canary). BrandRagService gate — TODO (нужны моки Qdrant)
- [ ] **Валидация релевантности источников (дизамбигуация брендов-омонимов)** — ПРИОРИТЕТ. Текущий фильтр «имя бренда в title/snippet» слаб: для `MariDeniz` первый результат Яндекса — про диабет, только второй про бренд (ya.ru/search/?text=MariDeniz). Скрейпер может взять не тот сайт → ложные факты в RAG. Нужно валидировать, что страница вправду относится к ЭТОМУ бренду одежды:
  - усилить фильтр: требовать fashion-контекст (одежда/коллекция/магазин/streetwear…) рядом с именем, а не только имя;
  - LLM-классификатор по сниппету/первому экрану: «это страница бренда одежды X? да/нет» перед принятием источника;
  - помечать сомнительные источники (low-confidence) и не заземлять на них генерацию (гейтить как retrieval-gate);
  - на бренд: проверять, что найденный официальный сайт действительно fashion (а не однофамилец/омоним).
- [ ] **Валидация результатов поиска контактов** — та же дизамбигуация для enrich: найденные website/email/соцсети должны принадлежать бренду одежды, а не омониму (MariDeniz→диабет). Сейчас confidence ставит LLM по тексту; добавить fashion-проверку источника контактов + перепроверку, что домен/соцсеть про одежду, прежде чем сохранять в Brand/BrandLink.
- [ ] **Тюнинг качества**: gate MIN_SCORE/MIN_CHUNKS, top-k, размер чанка, multi-aspect аспекты — по результатам реального прогона
- [ ] **Перепроверить bge-m3** при апдейте ollama (ушли на qwen3-embedding из-за NaN в ollama 0.22)
- [ ] **SearXNG надёжность**: движки могут rate-limit'ить → пустые выдачи; circuit breaker есть (ac3cecc), но фактически живой движок один — google. yandex: parsing error НЕ лечится обновлением (проверено на latest 2026-06-04 — upstream-проблема: Яндекс отдаёт капчу/вёрстку, парсер падает); образ обновлён, выдачу несут bing+mojeek, canary защищает. ddg — connection error (вероятно блок IP), отложено. Бэкап конфига: /opt/searxng/settings.yml.bak-20260604
- [x] **CLAUDE.md**: дополнены таблицы команд/сервисов RAG-частью (раздел «RAG pipeline»)
- [ ] (minor) URL-нормализация кеша для доков, сохранённых до rtrim-правки (новые консистентны)

## Архитектура: готовность брендов / FAQ / агент-сервер / дрип-публикация (дизайн от агента-архитектора, 2026-06-04)

Цель: выложить расширенную базу в прод БЕЗ резкого скачка для Google — дрип-публикация с ramp-up, публикуются только «готовые» бренды (title+meta+description grounded+FAQ из ключевиков).

**Модель признаков готовности** — БЕЗ новой таблицы/тегов (минимализм): признаки = существующие сигналы + 4 новые колонки на `brand_rag_pipeline` (`faq_status` done|skipped|failed, `pushed_at`, `push_attempts`, `push_error`) + 2 на `brand` (`publish_pending`, `published_at`, прод). Единый предикат `isPublishReady()`: description+metaTitle+metaDescription непусты И status=done И faq_status∈(done,skipped) И keywords_status∈(found,not_found). Выборки — финдеры по паттерну стадий: `findForFaq()` (status=done AND faq_status IS NULL), `findReadyToPush()` (isPublishReady AND pushed_at IS NULL).

**FAQ (задача C)** — таблица `brand_faq` (question/answer/position/locale/source, FK brand CASCADE; реляционно — нужна сортировка и будущая локализация). Генерация `app:brand:faq`: вопросные/длиннохвостые фразы brand_keyword (как/где/сколько + low monthly_shows, топ 5-7) → ответы 27b ТОЛЬКО из RAG-фактов («нет факта — пропусти вопрос»). Без ключевиков → `faq_status=skipped` (FAQ опционален, не блокирует публикацию — вопросы «из головы» без спроса = низкий ROI + риск галлюцинаций). Рендер: аккордеон + FAQPage JSON-LD в `showv2.html.twig` (НЕ show — контроллер рендерит showv2). Стадия GPU-демона.

**Агент-сервер (прод не видит LAN!)** — REST `/api/v1` в том же Symfony-проекте: `POST /api/v1/brands/upsert` (бренд целиком: description/meta/keywords/faq/contacts/links + логотип/картинки **base64-байтами** — прод не может дозагрузить из LAN), `GET /api/v1/brands/{slug}/status`. Идемпотентность: upsert по `slug` (dev brand.id ≠ прод, external_id только аудит), транзакция целиком, `content_version` против ре-доставки. Auth: bearer `AGENT_API_TOKEN` (hash_equals) + HMAC-подпись тела `X-Signature` (как платёжные вебхуки), access_control PUBLIC_ACCESS + проверка в контроллере (firewall'ы не трогаем), rate_limiter 120/мин (создать config/packages/rate_limiter.yaml). Агент-пуш: `app:brand:push` (dev, сетевая стадия демона), retry до 3, pushed_at/push_attempts на pipeline.

**Дрип-публикация (прод)** — приехавший бренд: `status='new'` + publish_pending=1 (Statuses: Active/Disabled/Deleted/System/New — `Inactive` НЕ существует, CLAUDE.md ошибается; публичные запросы фильтруют status='active' → new авто-скрыт из каталога/hub/sitemap). Cron-команда `app:brand:publish-tick` раз в час: окно 9–23 MSK (явная TZ Europe/Moscow!), sleep(rand(0..45мин)), ramp-up БЕЗ хранимого state: `w=floor((now-PUBLISH_LAUNCH_DATE)/7д); T(w)=min(28, round(5*1.125^w))`; самокоррекция: `p=(T - published_today)/ticks_left`; за тик публикуем `n=floor(p)+Bernoulli(frac(p))` СЛУЧАЙНЫХ готовых брендов (один-за-тик не дотягивает до CAP при 15 тиках/день), флип status='active'+published_at.

**План внедрения — СДЕЛАН ЦЕЛИКОМ 2026-06-04** (aa45f2e, 8bdd5c9, f0f24fb, a8e60f7, 9f0b38d, 72680fb): 1) ✅ миграции флагов + isPublishReady + финдеры; 2) ✅ brand_faq + app:brand:faq + стадия демона (Кисленько: 5 grounded Q/A); 3) ✅ рендер FAQ + JSON-LD (boilerplate-FAQPage вытесняется реальным; два FAQPage нельзя); 4) ✅ REST API (auth через X-Agent-Token — Authorization СРЕЗАЕТСЯ nginx/fpm; curl-проверены 401/created/skipped/updated); 5) ✅ app:brand:push (E2E на dev: pushed_at+contentVersion); 6) ✅ publish-tick (тик опубликовал случайный бренд, самокоррекция published_today работает; published_at в МСК — иначе UTC-прод съезжает).
**БОЕВОЙ ЗАПУСК ВЫПОЛНЕН 2026-06-04 19:24:** прод (regru, /var/www/u3042786/data/wearbase.ru, PHP 8.2 CLI + 8.4 web) задеплоен rsync без --delete; миграции на проде ЛОМАЛИСЬ по порядку (brand_claim→client→product_category) — схема собрана диффом с dev: +46 таблиц (bootstrap_schema), +14 колонок ALTER (bootstrap_alters), справочники с данными, все миграции version --add --all; боевые AGENT-токены, PUBLISH_LAUNCH_DATE=2026-06-05, INDEXNOW_KEY; крон publish-tick раз в час (прод в MSK); тестовый пуш Кисленько — FAQ+JSON-LD живые на проде; push:30 включён в сетевой демон LLM-сервера. Бэкапы: ~/wearbase-prod-backup-*.sql.gz + код. УРОК: порядок миграций ≠ порядок эволюции dev-БД — на свежую базу не накатываются.

**Риски:** ramp-дрейф→env-якорь PUBLISH_LAUNCH_DATE; TZ-баг→явный Europe/Moscow; полу-обновление→транзакция; replay→content_version+HMAC; галлюцинация FAQ→grounded-gate+skipped; CAP недостижим→n за тик.

## Архитектура: полный краул сайтов брендов + ротация прокси + извлечение атрибутов (агент-архитектор, 2026-06-05)

Цель: качать сайт бренда ЦЕЛИКОМ (не 1 стр.) → обогащать структурой (стили/размерная сетка/направление/материалы/ценовой сегмент/гео) — moat, которого нет у конкурентов-каталогов.

**A. КРАУЛЕР:** wget -l5 ОТКЛОНЁН (не извлекает контент, не приоритизирует, взрыв на маркетплейсе). Решение: **sitemap-first + trafilatura focused_crawler** (УЖЕ установлена; CLI --sitemap/--crawl/--url-filter, уважает robots.txt). sitemap = детектор размера: 0-1 URL → одностраничник (Tilda), ≤50 → все ценные, >50 → только верхнеуровневые разделы. `CrawlUrlFilter` (отдельно от UrlFilter): keep about/delivery/sizes/catalog(1-2 ур.)/contacts/faq, drop cart/checkout/login/?sort/?filter/?page/search. Cap **CRAWL_MAX_PAGES=30/хост**. Дедуп URL→url_hash, контента→UNIQUE(brand_id,content_hash) (boilerplate схлопывается). JS-сайты: НЕ рендерить по умолчанию (Tilda/InSales server-rendered, отдают sitemap); пустой ответ (<MIN_TEXT_CHARS) → fallback Playwright (есть для E2E) только для таких.

**B. РОТАЦИЯ ПРОКСИ — ИСПРАВЛЕНО 2026-06-05 (предыдущая версия была НЕВЕРНА):** прокси НЕ для краула сайтов брендов (у них нет анти-бота, отдают любому; краул ~18ГБ — гнать через платный прокси бессмысленно) — прокси ТОЛЬКО для поисковиков, где баны. И баним там не мы, а САМ SearXNG, ходящий в Google/Яндекс со своего IP. → Прокси навешивается **внутри SearXNG (settings.yml `outgoing.proxies.all://` socks5, на сервере 192.168.2.43), НЕ в Symfony-коде вообще**. Несколько прокси в списке → SearXNG round-robin сам (свой код не пишем). Рычаг экономии ×2: per-engine override только для google+yandex (реальные баньщики), bing/ddg с IP сервера. **Symfony НЕ трогает прокси → «мина общего HttpClient» испаряется by construction**, scoped scraper.client из прошлой версии УДАЛЯЕТСЯ (не пишется), WebScraperService остаётся на дефолтном клиенте БЕЗ изменений. Единственная проверка: на сервере НЕТ глобального http_proxy/https_proxy (иначе trafilatura-подпроцесс случайно пойдёт через прокси). Тип: residential/mobile РФ обязательно (datacenter Google/Яндекс канонически банят — по существу, не из-за объёма). **Бюджет (исправлен — через прокси идёт SERP-HTML ~150-300КБ/запрос, не JSON!):** 8-9 запросов/бренд × движков × 6000 = ~10-50ГБ полный обход = ~$30-400 residential ($3-8/ГБ); выигрыш разделения — 18ГБ краула УХОДЯТ со счётчика прокси ($0). Граница: вся прокси-конфигурация = devops на сервере (settings.yml + рестарт), код приложения 0 изменений. canary/SearxUnavailable/breaker/QUERY_SLEEP — ОСТАЮТСЯ (round-robin не health-aware, бан реже но не исключён).

**C. ХРАНЕНИЕ:** НЕ сырой HTML, НЕ скриншоты — только cleanText (rawText уже null). Переиспользуем brand_source_document, новый sourceType `own_page`. Объём: ~30стр×6000 = ~180k строк (~2ГБ TEXT), Qdrant ~1.5M точек (~6ГБ) — ок.

**C2. ИЗВЛЕЧЕНИЕ АТРИБУТОВ (главная цель):** стадия `extract` — ollama qwen3.5:27b **structured output** (format=JSON-schema на /api/chat, подтверждено; fallback format:json+схема в промпте) по АГРЕГИРОВАННОМУ тексту бренда (не постранично). Схема: styles/categories/направление/gender/sizes/materials/price_segment/geo. Складывать ГИБРИДОМ: styles/sizes/categories → существующие BrandStyle/BrandSize/ProductCategory ManyToMany (нормализация по словарю); остальное → новая таблица `brand_attribute` (EAV: brand_id/name/value/provenance). Валидация — переиспользуем краудсорс: target_type='brand_attribute' в BrandDatapoint::FIELDS, голоса ✓/✗ работают без нового кода. Статус: attributes_status/extracted_at на BrandRagPipeline, findForExtract().

**D. ПАЙПЛАЙН — НЕ отдельная стадия, а развёртка own_site В ОЧЕРЕДЬ:** fetch скачал own_site-сид → focused_crawler даёт внутренние URL → CrawlUrlFilter → enqueue обратно в brand_source_url типом `own_page` tier=1 → обычный fetch их дренит. Идемпотентность/докачка после обрыва — бесплатно (url_hash + reclaimStale). **2 ЛОВУШКИ:** (1) CAPS own_site=2 в двух слоях блокируют развёртку → own_page отдельный тип со своим cap, минует discover-CAPS; (2) finalizeIfDrained финализирует рано → own_page persist+flush как pending ДО пометки own_site-сида fetched (иначе бренд финализируется в середине краула — критично!). **ОТДЕЛЬНЫЙ СЕРВЕР НЕ НУЖЕН** — прокси снимает баны, скрейп остаётся на 192.168.2.43; параллелизм 4-8 воркеров (Messenger по дизайну очереди), до него — RagDaemon с --max-urls ломтями.

**План:** MVP — 1) scoped scraper.client+прокси (verify: discover без CAPTCHA, Qdrant/ollama мимо прокси); 2) CrawlUrlFilter+sitemap-разворот в fetch+фикс persist-before-finalize. Фаза 2 — extract (миграция brand_attribute+колонки, LlmService::extractBrandAttributes structured, app:brand:extract, маппинг на словари) + краудсорс. Фаза 3 — extract в демон, шарды, Messenger.

**Стоимость прокси:** rotating residential РФ ~$3-8/ГБ; ~30стр×100КБ×6000 ≈ 18ГБ = **~$54-145 за полный обход** базы. Datacenter $0.5-1/ГБ но не выдержит Яндекс — не для основного скрейпа.

**Риски:** прокси-утечка LAN→scoped+no_proxy явно тестировать; краул маркетплейса→sitemap-детектор+cap30+drop-фасеты; drain рано→persist own_page до fetched; cap-рассинхрон→отдельный тип own_page; галлюцинация атрибутов→structured+grounded+краудсорс; ротация бьёт rate-limit→per-host пауза обязательна.

## Архитектура: парсинг каталогов/карточек → ассортимент + размерная сетка (агент-архитектор, 2026-06-05)

Расширение crawl-стадии (НЕ новый парсер карточек, НЕ товарный каталог): вытащить АТРИБУТЫ бренда (ассортимент-категории, размерный ряд, материалы), а не товары.

**🔴 БЛОКЕР (чинить независимо!):** размерные таблицы СЕЙЧАС молча выбрасываются обоими путями fetch — trafilatura `--no-tables` (прод-primary) + DomCrawler NOISE_TAGS режет form/select/option/table. Размерная сетка живёт ровно там (таблица /sizes или select в карточке) → на проде размерный ряд в текст НЕ попадает. Фикс: `WebScraperService::fetchCleanText($url, bool $keepTables=false)` — при keepTables trafilatura с `--include-tables`, DomCrawler без вырезания таблиц; app:brand:fetch выбирает режим по sourceType (sizes/product_sample → keepTables). Обычные страницы как есть (таблицы шумят эмбеддинги прозы).

**Объём (cap 30 own-стр/бренд НЕ растёт, приоритет внутри):** INFO (about/delivery/sizes/contacts/faq) — все; CATEGORY (catalog 1-2 уровня) — остаток; PRODUCT_CARD семпл **≤6-8** (новый тип `product_sample`, relevance 0.40 — карточки не загрязняют grounding прозы; читает только extract, generate-content даун-вейтит); ORDINARY добор до 30. Семпл 6-8 карточек достаточно LLM понять ассортимент/материалы/размеры — НЕ весь каталог (тысячи SKU × 6000 = взрыв).

**CrawlUrlFilter.classify()** → DROP|INFO|CATEGORY|PRODUCT_CARD|ORDINARY (rank() — обёртка). PRODUCT_CARD: /product//tovar//p//item/ + слаг/id-хвост или глубина≥3 под catalog; CATEGORY: catalog/collection глубина 1-2 без товарного хвоста. ⚠️ TYPE_CATALOG уже занят (внешний маркетплейс из discover) — внутренние категории идут own_page.

**Извлечение (стадия extract, как в дизайне «полный краул» C2):** qwen format=JSON-schema по АГРЕГИРОВАННОМУ тексту бренда (приоритет size+category в агрегат, бюджет контекста — 30стр×12k переполнят qwen!) → {styles,categories,gender,sizes,materials,price_segment,geo}. Маппинг: categories→ProductCategory MtM (засеян ✓), styles→BrandStyle, sizes→BrandSize MtM (⚠️ ПУСТ — нет сидов/фикстур → **create-on-miss** по title+slug ИЛИ сырой ряд "42-52" в brand_attribute), остальное→brand_attribute EAV. attributes_status/extracted_at на pipeline, findForExtract(), краудсорс target_type='brand_attribute'.

**ЮРИДИКА:** храним атрибуты УРОВНЯ БРЕНДА (категории/размерный ряд/сегмент/стили), per-SKU цены/позиции НЕ сохраняем; семпл-карточки = свидетельство для LLM, выбрасываются после extract. Product/ProductImport остаются owner-upload only. Копирования чужого каталога нет. OCR размерных картинок — вне скоупа (явный пробел).

**СДЕЛАНО 2026-06-05** (0baef2a,a582837→342bcfc): 1)✅ table-preserving fetch (блокер размеров); 2)✅ classify+product_sample семпл≤8 (12/12 unit); 3)✅ стадия app:brand:extract — TISVAL→15 атрибутов (категории/размеры/материал/сегмент/гео/стиль) в brand_attribute EAV, стадия в GPU-демон. Осталось: краудсорс target_type=brand_attribute, рендер атрибутов на showv2, промоушн в справочники после валидации.

## Архитектура: email-активация владельцев брендов (5 агентов: архитектор+маркетолог+devops+backend+frontend, 2026-06-05)

Воронка: публикация страницы → письмо владельцу → open → click → регистрация/claim → подписка. ТОЛЬКО ДИЗАЙН, реализация отдельно.

**ЮРИДИКА (определяет ВСЁ):** письмо №1 — ЧИСТОЕ УВЕДОМЛЕНИЕ без продажи. ФЗ-38 ст.18: реклама по email только с согласия (его нет!); защита — «это не реклама»: персонифицировано (ЕГО бренд, ЕГО адреса магазинов из БД), нет продвижения платных услуг → НИКАКИХ цен/подписок в письме. Подписка продаётся ПОСЛЕ claim внутри ЛК. ФЗ-152: законный интерес + общедоступные источники + журнал источника каждого email + право удаления (=unsub). Обязательно: реестр операторов РКН, политика ПДн, юрподвал. Серия: максимум 2 касания (№2 через 5-7 дн ТОЛЬКО открывшим-не-claimнувшим). Перед запуском — ревью юристом.

**ДОСТАВКА:** SMTP reg.ru shared — категорически нет (лимиты, shared-IP в DNSBL, чужой DKIM, нет webhooks). Выбор: **RuSender** (5000/мес бесплатно, российские IP, постмастеры Mail.ru/Яндекс, полные webhooks); план Б Unisender Go. Поддомен **mail.wearbase.ru** (SPF/DKIM/DMARC p=none→quarantine) — основной домен НЕ трогаем. Регистрация в postoffice.yandex.ru + postmaster.mail.ru.

**АРХИТЕКТУРА (вся воронка на ПРОДЕ):** таблица `brand_outreach` — широкая строка на бренд (brand_id UNIQUE, email-снимок, send_token CHAR(32) random, sent/opened/clicked/unsubscribed/bounced(ТОЛЬКО hard; soft→last_error)/attempts; INDEX(email) НЕ unique — suppression ПО EMAIL: один владелец=N брендов). Endpoint'ы: GET /e/o/{token}.gif (пиксель: UA-denylist + grace 5с от sent_at; ВСЕГДА 200), GET /e/c/{token} (цель из slug СЕРВЕРОМ — open-redirect невозможен; +UTM), GET+POST /e/u/{token} (RFC 8058), POST /api/v1/email/webhook (hard/soft маппинг), GET /api/v1/outreach-stats (когорты 7/14/30д: sent→opened→clicked→claimed(created_at>sent_at)→subscribed; дашборд/отчёт по агент-API). KPI — КЛИК (opens завышены Apple MPP/сканерами). BrandOutreachMailer fail-open, врезка в PublishTickCommand рядом с IndexNow, app:outreach:retry (≤3, backoff 6ч). access_control: ^/e PUBLIC_ACCESS.

**РАЗРЕШЁННЫЙ КОНФЛИКТ (дрип случаен vs маркетингу нужна когорта A первой):** warmup-фаза — первые ~50 писем шлёт ОТДЕЛЬНАЯ команда app:outreach:send по когорте A (живой сайт+магазины+валидный email+спрос; 10→15→25 за 3 дня, цель 0 жалоб/open≥20%), ПОТОМ включается авто-врезка в publish-tick. Валидация email до отправки: MX-check + платный верификатор на сомнительные. Пороги (объём>200/день): complaint Mail.ru >0.05% стоп, hard bounce >3-4% стоп; на warmup — абсолютные числа.

**ПИСЬМО:** прехедер; ТЕКСТ-лого (картинки у холодных заблокированы); крючок-карточка ЕГО данных («Всё верно?» — мотивация исправить сильнее регистрации); ОДНА bulletproof-CTA «Открыть страницу бренда→»; подвал кто/почему/юрлицо/unsub. ≤90 слов, plain-text обязателен. Темы A/B: «[Бренд] — мы опубликовали страницу о вашем магазине на Wearbase» / «Проверьте данные о [Бренд] в каталоге Wearbase».

**ПОСАДОЧНАЯ:** страница бренда + липкий top-бар «Это ваш бренд?» (НЕ лендинг). Скрытие от Google: JS-рендер при utm/куке + data-nosnippet + не отдавать в HTML без маркера. НАХОДКА: brand_claim_new требует ROLE_USER → воронка 5 шагов с 2 барьерами; сократить: кнопка плашки → app_register?brand={id}&next=brand_claim_new (регистрация С КОНТЕКСТОМ бренда), верификация дефолтом «код на email бренда».

**План реализации:** 1) RuSender+DNS (ручное); 2) brand_outreach+Mailer+шаблон; 3) /e/*+webhook; 4) плашка+register-контекст; 5) app:outreach:send (warmup 50 за 3 дня); 6) авто-врезка в publish-tick; 7) outreach-stats в дашборд/отчёт; 8) письмо №2. Бенчмарки: delivery>95% / open 15-30% / click 4-10% / claim 2-5%; open<10% у когорты A = мы в спаме, стоп.

## Архитектура: аналитика бренда + GSC (дизайн от агента-архитектора, 2026-06-04)

Закрыть проданную фичу «Аналитика» Премиум-тарифа (has_analytics нигде не используется!) + GSC для мониторинга дрип-публикации (Фаза 6). ВАЖНО: кликов по контактам на странице сейчас НЕТ — это новый JS-маяк.

**Контур 1 — ЛК (MVP за день, БЕЗ GSC):** таблица `brand_event_daily` (brand_id, day, event_type page_view|click_phone|click_site|click_social|click_store, cnt; UNIQUE по тройке; запись нативным `INSERT ON DUPLICATE KEY UPDATE cnt=cnt+1` — горячий путь без ORM; retention не нужен — агрегаты). Маяк: `POST /brand-data/{slug}/event` (паттерн dp-vote: stateless, rate-limiter brand_event ~120/час/IP, UA-denylist bot|crawl|spider|headless; бот-фильтр = сам факт JS). Страница `/brand/analytics` (BrandAnalyticsController extends BrandDashboard): KPI-cards (просмотры 30д, клики, заказы, выручка sumPaidRevenue, голоса валидации) + Chart.js (CDN inline) графики; gate `getActiveSubscription()?->getTariff()?->hasAnalytics()`; пункт меню ВСЕГДА виден, не-Премиум видит upsell-заглушку. OrderRepository: добавить дневную группировку.

**Контур 2 — GSC:** Service Account (НЕ OAuth — один property, серверный крон): SA в Google Cloud → добавить его email в Search Console users; `composer require google/apiclient`; env GSC_CREDENTIALS_PATH + GSC_SITE_URL (sc-domain:wearbase.ru). Таблицы: `gsc_page_stats` (page_url, brand_id резолвнутый по slug без локали — суммирует 9 локалей, day, impressions/clicks/position, query NULL=агрегат) + `gsc_index_status` (1 строка/бренд: verdict/indexed/last_checked_at — текущее состояние, не история). Команда `app:gsc:sync` (cron 1/день): Search Analytics одним батчем dimensions=[page] rowLimit=25000 (лаг GSC 2-3 дня!); URL Inspection — лимит 2000/день на 6000 страниц → cap 1500: приоритет published_at DESC за 7 дней (свежий дрип = главный риск), остаток round-robin по last_checked_at; полный обход ~4 дня. `--report`: алерты в var/log/gsc.log (indexed_ratio<0.5 свежих, падение impressions>50% д/д).

**КРИТИЧНО — drip-health сигнал строго FAIL-OPEN:** indexed_ratio свежеопубликованных — read-only метрика; publish-tick МОЖЕТ читать множитель темпа, но отсутствие/пустота GSC-данных = множитель 1.0. Мониторинг не должен уметь остановить публикацию. Хранимого ramp-state НЕ вводить (ramp намеренно stateless от PUBLISH_LAUNCH_DATE).

**План:** MVP (миграция brand_event_daily → beacon+JS → страница ЛК+gate → дневные группировки Order) — закрывает обещание тарифа сразу; Фаза 2: GSC (apiclient, GscClient fail-open, миграции, sync+cron, GSC-блоки в ЛК); Фаза 3: dimension query (топ-запросы), drip-множитель, email-алерты.

**Риски:** GSC-сигнал тормозит дрип (fail-open!); боты с JS (rate-limit+UA-denylist, визитор-дедуп не вводим); квота Inspection (cap+приоритизация); лаг GSC 2-3 дня (подписать в UI); локали ×9 (ключ по brand_id); vendor-раздувание google/apiclient (изолирован в GscClient, MVP без него).

## Архитектура: краудсорс-валидация данных бренда (дизайн от агента-архитектора, 2026-06-04)

«Исправить неточность» (Яндекс Карты) + голосование ✓/✗ за data-point'ы (телефон/email/адрес бренда, BrandLink, BrandStore): посетители валидируют шумный enrichment-контент (кейс Zatmenie — адрес ночного клуба).

**Модель (2 таблицы, ленивое создание строк):** `brand_datapoint` (полиморфный ключ brand_id+target_type+target_id+field; `provenance` enrichment|owner|crowd_confirmed; `state` active|doubtful|hidden|pinned; счётчики confirm/reject_window; `owner_edited_at` — owner-специфичный timestamp, т.к. brand.updated_at бьётся enrichment'ом!) + `brand_datapoint_vote` (vote confirm|reject + suggestion; `voter_hash`=sha256(ip+daily_salt+UA) — дедуп без PII/152-ФЗ; UNIQUE(datapoint,voter_hash) → повтор=upsert; вес: аноним 1, залогинен 3 — пороги по Σweight, не count).

**Confidence/переходы (режим-зависимые):** unclaimed: reject_window≥3→doubtful (бейдж), ≥5 и reject>2×confirm→hidden (скрыт со страницы + queued_revalidate_at); confirm≥5 без reject→pinned (crowd_confirmed). Owner-правка → provenance=owner, счётчики reset. hidden не удаляется — ждёт ре-обогащения.

**Три режима бренда** (`brand.owner_state` unclaimed|claimed|abandoned — денормализован, claim-предикат = BrandUser owner accepted): (а) unclaimed — голоса агрессивно скрывают, авто-ре-обогащение; (б) claimed-live — owner-данные неприкосновенны, reject = только нотификация владельцу + копится; (в) abandoned — детект КОМБИНАЦИЕЙ (подписка истекла И (нет owner-правок >6 мес ИЛИ notify_unanswered≥3); lastLoginAt в User НЕТ, updated_at не годится) → крон `app:brand:detect-abandoned` → доверие деградирует, возврат к режиму (а); owner вернулся → назад в (б).

**Обратный канал прод→агент (agent-pull, прод не видит LAN):** `GET /api/v1/revalidation-queue` (token-auth) — агент поллит скрытые точки, ре-обогащает локально, возвращает существующим upsert. **КРИТИЧНО — owner-guard в BrandIngestService:** сейчас upsert delete-and-replace затирает ВСЁ → при claimed пропускать owner-поля и удалять только enrichment-строки (provenance-фильтр), guard независим от content_version (owner-правки на проде version не бампают).

**Endpoints/анти-абьюз:** POST `/brand/{slug}/datapoint/vote` (main firewall, PUBLIC_ACCESS, rate_limiter `brand_vote` 20/час/IP, CSRF off как у вебхуков); UI ✓/✗/«исправить» inline-JS на showv2, data-nosnippet (SEO).

**План:** MVP СДЕЛАН 2026-06-04 (fe5c247): миграция+сущности, vote endpoint (/brand-data/{slug}/vote — PUBLIC_ACCESS ДО ^/brand, иначе перехват ROLE_BRAND_MANAGER!), confidence режима (а), фильтр hidden+кнопки на showv2, GET /api/v1/revalidation-queue, owner-guard в ingest. E2E проверен: 5×reject→hidden→адрес исчез→в очереди агента. Отложить: (6) режим (б) — зависит от LK CRUD магазинов (задача brand_store); (7) abandoned-детект; (8) pinned/веса/модерация в EasyAdmin.

**Риски:** накрутка конкурентом (Σweight+окно 30д+порог только unclaimed); затирание owner-данных (provenance-guard); ложный abandoned (только комбинация сигналов); PII (хэш с daily salt); SEO (nosnippet, suggestion не рендерится); осиротевшие datapoint при удалении link/store (крон-уборка).

## Архитектура очереди (дизайн от агента-архитектора)

**Центральный факт:** БД (MySQL) сейчас на Mac (`DATABASE_HOST=127.0.0.1`), GPU-сервис на 192.168.2.43 — **у сервера нет доступа к БД**. Это «петля» всего дизайна.

**Решение (рекоменд.):** Symfony Messenger + **Doctrine-транспорт** (уже установлен; `messenger_messages` в MySQL, `SKIP LOCKED`). Транспорта три: `scrape` (IO, 4-8 воркеров), `keywords` (rate-limited, 1-2), `gpu` (embed+generate+enrich, **суммарно ≤ OLLAMA_NUM_PARALLEL=4** — общий GPU!). Failure-транспорт `failed`, retry exponential. Middleware `doctrine_ping/close_connection` вместо ручного resetManager.

**Доступ к БД — РЕШЕНО (Mac и сервер в одной локалке, Tailscale НЕ нужен):**
- Сервер ходит в MySQL Mac'а по LAN напрямую: на Mac `bind-address` на LAN-интерфейс (не только 127.0.0.1), грант `wearbase@'192.168.2.%'`, серверный `.env.local` `DATABASE_HOST=<lan-ip-мака>`. Воркер пишет через Doctrine, код не меняется. (Альтернатива — перенести MySQL на сервер, тогда всё localhost.)
- Doctrine-транспорт Messenger работает (сервер видит БД по LAN). Fallback с Redis/result-очередью НЕ нужен.

**Сообщения/хендлеры:** вынести `processBrand` каждой команды в headless-сервис `src/Service/Rag/Brand*Processor.php` (один код — два входа: команда + хендлер). Сообщения `ScrapeBrand/EmbedBrand/GenerateBrandContent/CollectKeywords/EnrichBrandContacts` (только brandId+флаги). **Цепочка** (scrape→dispatch embed→dispatch generate) для латентности + **диспетчер-cron** `app:rag:dispatch` для посева/backstop (через finders, дедуп через новые `*QueuedAt` колонки).

**Воркеры:** systemd-шаблон `wearbase-consumer@.service` (`messenger:consume <transport> --limit=200 --time-limit=3600 --memory-limit=512M`, Restart=always). Заменяет `--shard/--total`.

**Ретраи:** Messenger = транзиентные сбои (throw → retry); статус-машина `*_failed`+attempts = ТЕРМИНАЛЬНЫЕ (через `WorkerMessageFailedEvent` subscriber, `!willRetry()`), чтобы не двойного счёта.

**Миграция (по шагам, каждый shippable):** 1) extract processors; 2) messages+handlers+messenger.yaml; 3) `app:rag:dispatch`+`*QueuedAt`; 4) деплой на сервер + доступ к MySQL Mac'а по LAN (bind-address+грант); 5) terminal-failure subscriber+chain; 6) systemd-консьюмеры, низкие counts→масштаб.

**Риски:** латентность БД с сервера (round-trips per brand — ок при текущем темпе); GPU-thrashing embed↔generate (27b ~16-20ГБ — проверить, влезают ли обе модели; иначе разнести `gpu_embed`/`gpu_generate`); bloat `messenger_messages` (cron `messenger:failed:remove`); утечки памяти консьюмеров (обязательны `--limit/--time-limit/--memory-limit`).

Файлы: `config/packages/messenger.yaml`, новые `src/Service/Rag/`, `src/Message/`, `src/MessageHandler/`, `src/EventSubscriber/`, `src/Command/DispatchRagJobsCommand.php`, `BrandRagPipeline` (+`*QueuedAt`), `BrandRepository` (дедуп-clause).
```

## Архитектура: Discovery → URL-очередь → Fetch (дизайн от агента-архитектора)

Разбить монолитный `app:brand:scrape` на 3 концерна (сходятся в тот же `STATUS_SCRAPED` — embed/generate не трогаем):
```
app:brand:discover  (лёгкий, только SearXNG)  → наполняет brand_source_url + ставит has_own_site
app:brand:fetch     (тяжёлый, trafilatura)    → дренит очередь → brand_source_document → status=scraped
app:brand:scrape    (без изменений)           → монолит-fallback по --id
```

**Многоуровневый discovery** (caps на enqueue, чтобы очередь была сбалансирована):
- **T1 own_site** (cap 1–2): DB website-link → `ContactVerifier::verifyUrl`; угадывание `{slug}.ru/.com`; SearXNG «{бренд} одежда официальный сайт». Скоринг own-site confidence.
- **T2 corpus** (marketplace ≤3, catalog ≤4): «{бренд} одежда/купить/{город} магазин».
- **T3 mentions/social** (social ≤4, article_review ≤3, mention ≤3): соц-ссылки из БД, «{бренд} отзывы/обзор».
- Таксономия `source_type`: `own_site|marketplace|catalog|article_review|social|mention` (единая для очереди→документа→Qdrant payload; legacy `official_site→own_site`).
- ⚠️ Cap'ы в дизайне выше — ИСХОДНЫЕ. Текущие (948ce41) подняты ≈ до потолка 30/бренд, живут в ДВУХ слоях (BrandSourceFinder + DiscoverBrandSourcesCommand::CAPS) — см. «Discovery-split — СДЕЛАН».

**«У бренда может не быть сайта» — 2 фазы:**
- Фаза A (discover, лёгкая): кандидат живой (`verifyUrl`) + не маркетплейс/соцсеть + slug-в-хосте/DB-ссылка + (для поисковых) имя+fashion-термин → `has_own_site=provisional`. Если все мёртвы / только маркетплейсы-соцсети / нет fashion-контекста → `has_own_site=false`.
- Фаза B (fetch, авторитетная): own_site реально скачался с ≥MIN_TEXT_CHARS релевантного текста → confirmed; иначе demote в false (ловит «живой, но не тот домен»).

**Дизамбигуация (MariDeniz→диабет)** — на discover, без скачиваний (хватает SearXNG title+snippet):
- Co-occurrence: имя бренда (или slug) И ≥1 fashion-термин в title+snippet.
- `relevance_score` 0–1: +имя в title/snippet, +fashion co-occur, +own-site сигналы, **−deny-list** («диабет/сахар/медицин/клиника» без fashion → штраф ловит класс MariDeniz). Ниже floor (~0.35) — не кладём в очередь.
- LLM tie-breaker (узко, опц.): только для неоднозначного T1 own-site («Это офиц. страница бренда одежды X? да/нет»). Не в hot-path.
- **Carry-forward**: fetch копирует `relevance_score`+`source_type` на `BrandSourceDocument` → embed кладёт в Qdrant payload → `BrandRagService` взвешивает own_site выше и гейтит низко-релевантное.

**Очередь — таблица `brand_source_url`** (DB-очередь сейчас, Messenger потом):
```sql
brand_source_url(id, brand_id, url VARCHAR(1024), url_hash CHAR(64), source_type,
  tier TINYINT, relevance_score FLOAT, status pending|claimed|fetched|failed|skipped,
  attempts, last_error, discovered_at, claimed_at, fetched_at,
  UNIQUE(brand_id, url_hash), INDEX(status,brand_id), INDEX(brand_id,tier), FK brand CASCADE)
```
- ⚠️ Уникальный индекс по `url_hash` (sha256), НЕ по url — VARCHAR(1024) utf8mb4 > 3072 байт лимита InnoDB (тот же урок, что content_hash).
- Enqueue: `ON DUPLICATE KEY UPDATE` по `(brand_id,url_hash)` — дедуп бесплатно; caps по типу в PHP.
- Drain: атомарный claim `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 9), порядок `tier ASC, relevance_score DESC` (own_site/высокая уверенность раньше); шард `MOD(brand_id,total)`. Успех→fetched+документ; сбой→attempts++/failed; протухший claimed реклеймится.

**Рефактор:** `BrandSourceFinder::discoverTiered(): DiscoveredUrl[]` (DTO url/sourceType/tier/relevanceScore/live) + `SourceTypeClassifier`; старый `discover()` оставить шимом (монолит цел). Новые сущности `BrandSourceUrl`+repo; команды `DiscoverBrandSourcesCommand`/`FetchBrandSourcesCommand` (fetch ПЕРЕИСПОЛЬЗУЕТ кеш 30д + `existsForBrandHash` из монолита). `BrandRagPipeline` +`has_own_site`+`discovered_at`; `BrandSourceDocument` +`relevance_score`+расширенный `source_type`.

**Шаги (incremental):** 1) миграция+сущности; 2) `discoverTiered`+DTO+classifier (шим); 3) `app:brand:discover`; 4) `app:brand:fetch` (A/B рядом с монолитом на разных шардах); 5) relevance/source_type в Qdrant + has_own_site в генерацию; 6) депрекейт монолита (кроме --id); 7) позже — Messenger `ScrapeUrl`.

**Риски:** SearXNG rate-limit→пустые тиры (idempotent re-run добирает); омоним-false-pos (deny-list+score+LLM tie-breaker+gate); рост таблицы (caps+TTL-prune); over-fetch маркетплейсов (cap 3+host-cap 4+tier-порядок); живой-но-не-тот домен (Фаза B demote); EM (resetManager как в монолите).

Новые файлы: `src/Entity/BrandSourceUrl.php`, `src/Repository/BrandSourceUrlRepository.php`, `src/Command/DiscoverBrandSourcesCommand.php`, `src/Command/FetchBrandSourcesCommand.php`, `src/Service/Discovery/DiscoveredUrl.php`, `src/Service/Discovery/SourceTypeClassifier.php`, миграция `brand_source_url`.
## ⚠️ Грабли деплоя (укусило дважды)
- **rsync индивидуальных файлов на прод с --relative/неполным путём кладёт *Command.php в src/Service/** → Symfony autowire ищет класс App\Service\XxxCommand, не находит → весь сайт 500. Фикс: всегда ПОЛНЫЙ rsync `rsync -az --exclude .git --exclude var --exclude .env.local --exclude config/secrets ... ./ regru:wearbase.ru/` (без --delete, без --relative), потом `find src/{Service,Entity,Controller} -maxdepth 1 -name '*Command.php' -delete` для подчистки старых strays, затем cache:clear --no-debug.
- После любого деплоя НОВЫХ команд на сервер/прод — обязателен `cache:clear --no-debug` (no-debug контейнер кэширует список команд отдельно).

## Инцидент качества публикации + re-delivery (2026-06-08)

**Триггер:** на проде опубликован Majestic с описанием-ОТКАЗОМ модели («невозможно составить описание… факты про majestic.com»). Корень: у бренда не было сайта-ссылки → discover угадал `majestic.com` (SEO-чекер беклинков) вместо `majestic.store`. Масштаб: **55** брендов на проде с refusal-описанием (текст-отказ проходил валидацию → done → публикация).

### Сделано (коммиты a300cdc, 7a3496a)
- **Refusal-гейт:** `ContentValidator::isRefusal()` (маркер `НЕДОСТАТОЧНО_ФАКТОВ` + anchored-паттерны, высокая точность, без ложных). `LlmService` промпт grounded-режима выводит маркер при отказе. `generate-content`: отказ → статус `review` (НЕ `done`), мусор не сохраняется, без retry.
- **Статус `BrandRagPipeline::STATUS_REVIEW`** + админ-страница `/admin/rag/review` (меню «Верификация брендов»): список с описанием/ссылками/прод-линком + действия Переобогатить (reset→pending+requeue URL) / Скрыть (soft-disable локально + прод-unpublish).
- **Прод-unpublish:** `POST /api/v1/brands/unpublish` (auth X-Agent-Token + HMAC X-Signature) → `BrandIngestService::unpublish` (status=Disabled + publish_pending=0, без delete). Admin «Скрыть» вызывает по HMAC, fail-soft.
- **Re-delivery (version-delta):** `brand_rag_pipeline.content_changed_at` (миграция Version20260608) + предикат `findReadyToPush`: `pushedAt IS NULL OR contentChangedAt > pushedAt`. `markContentChanged()` + стампы в generate/extract/faq/enrich-contacts/contacts-refresh. **Чинит:** обогащение ПОСЛЕ первого пуша (атрибуты/магазины/контакты/faq/регенерация) раньше НЕ доезжало (push одноразовый `pushedAt IS NULL`); теперь авто-переотправляется.
- **meta «| WEARBASE» двойной хвост:** showv3.html.twig дописывает хвост только если его нет; `LlmService` fallback без запечённого хвоста, cap title 60→48; БД-бэкфилл 67 строк (хвостов 0).
- **Majestic:** own_site `majestic.store` (brand_link website), majestic.com → `SCRAPE_EXCLUDED_DOMAINS` (.env.local), загрязнённый корпус удалён (29 doc/32 url), pipeline сброшен в pending.
- **app:brand:pipeline:reset-phantoms** — сброс фантомных pipeline-статусов (1151 «embedded» без векторов/доков).
- **Прод-чистка:** 55 refusal-брендов на проде → `status='disabled'`+`publish_pending=0` (ssh regru, soft, обратимо). 55 локально сброшены в pending на пересбор.

### ⚠️ Открытые follow-up
1. **Ре-активация disabled→каталог.** 55 прод-брендов в `disabled`. После пересбора в хороший контент `upsert` НЕ флипает `disabled→active`/`new` — останутся скрыты. Нужно: действие «Вернуть» на /admin/rag/review ЛИБО авто-правило в `BrandIngestService::upsert` (бренд disabled + описание НЕ refusal → вернуть в `new`+publish_pending). **Без этого хорошо-переобработанные не вернутся в каталог.**
2. **Деплой unpublish на прод.** Endpoint `/api/v1/brands/unpublish` есть только на dev (прод → 404). Задеплоить (полный rsync, см. «Грабли деплоя») + cache:clear, иначе «Скрыть» в админке работает только локально (fail-soft).
3. **Запуск/рестарт RAG-демона** для пересбора 55 сброшенных. Долгоживущий демон — рестарт, чтобы подхватил `SCRAPE_EXCLUDED_DOMAINS=majestic.com` (env читается на старте процесса).
4. **E: email-домен-guard** перед outreach — `Brand.email` может быть от чужой сущности (как majestic.com), нет проверки соответствия домену бренда.
5. **GenerateBrandContentCommand:425** ещё режет metaTitle до 60 (безвреден — вход уже ≤48 из LlmService); при желании привести к 48.
6. **Сольная WIP-ветка:** правки переплетены с незакоммиченным WIP (contacts:refresh: `findForContactRefresh`/`extractContactsFromContext`/`BrandRefreshContactsCommand`, prodOutreach-рефактор) — въехали в коммиты вместе (интерактивный `git add -p` в среде недоступен).

## SEO-контур: блог, городские посадочные, единый шаблон, мобильная шапка (2026-06-12)

**Сделано:**
- **Блог**: `Article` (+миграция `Version20260612_blog_articles`), `BlogController` (`/ru/blog`, `/ru/blog/{slug}`), шаблоны `tailwind/blog/*`, schema.org Article+Breadcrumb, sitemap, админка «Контент → Статьи блога» (content = raw-HTML textarea — Trix ломает таблицы). 4 статьи опубликованы (комиссии-2026, гид по российским брендам, исход с WB, покупка напрямую) — перелинкованы, исходники в `_docs/blog-drafts/`.
- **301**: `/marketplace-commissions` → `/ru/blog/komissii-marketpleysov-2026` с fallback-рендером, пока статья не создана в БД (важно для прода: после деплоя вставить статьи, см. ниже).
- **Городские посадочные**: `/ru/cities` + `/ru/cities/{slug}` (`CitySlugger`, транслит на лету, без таблицы слагов), таргет «бренды одежды москва» (1470/мес) и хвост; ссылки с главной (топ-городов), из шапки и подвала; все города в sitemap. Wordstat-карта — `_docs/seo-keywords-2026-06.md` («бренды из регионов» = 0 показов, формулировка из подвала убрана).
- **Лендинги** переведены на общий `tailwind/base.html.twig` (сайтовые шапка+подвал); `tailwind/landing/base.html.twig` и `public_html/css/landing.css` удалены. В base добавлен рендер flash (нужен формам лидов).
- **Подвал**: мёртвые якоря `#faq` убраны, форма подписки реально шлёт в `LandingLead` (source=footer-subscribe), добавлены ссылки Без маркетплейсов/Комиссии/Блог/Бренды по городам.
- **Мобильная шапка**: бургер-меню (нав+авторизация+язык+валюта внутри), снаружи логотип+корзина+бургер. Проверено Playwright: было 481px на viewport 375 (горизонтальный скролл), стало 375px.

**⚠️ Деплой-шаги для этого релиза (после полного rsync, см. «Грабли деплоя»):**
1. `php bin/console doctrine:migrations:migrate --no-interaction` (создаст `article`).
2. Вставить 4 статьи в прод-БД (HTML из `_docs/blog-drafts/*.html`, скрипт-инсерт через PDO; UTC-даты! MySQL NOW() в Москве, PHP в UTC — использовать UTC_TIMESTAMP()).
3. `cache:clear --no-debug`.
4. Смоук: /ru/blog, /ru/cities, /ru/cities/moskva, 301 у /ru/marketplace-commissions, бургер на мобильном.

**Открыто:** падения PHPUnit (31) — предсуществующие (битые data providers «0 passed, 1 expected», БД-зависимые тесты), к этому релизу не относятся, чинить отдельно. Instagram-ссылка в подвале — решить про дисклеймер Meta.

---

## 2026-06-14 — closed-loop регенерация + конкурент-разбор + лиды

**Сделано:** версионирование контента (`brand_content_revision` + `BrandContentVersioner`),
closed-loop (`app:seo:evaluate-experiments`: GSC win/loss/откат/реген, окно 14д), `--regen-flagged`,
priority очереди, GSC-индекс из Search Analytics + sitemaps, noindex непереведённых локалей,
фикс тегов в описании, TG-корпус через `t.me/s/`. Контур в `scheduled_command` (env=dev).
Разбор vitrine.market → `docs/competitors.md`. Импорт **261 бренда-лида** конкурента
(`app:brand:import-leads`, status=new → discover).

**Открыто / next:**
- Поднять **.43** → discover/embed/generate для 261 новых + реген 82 флагнутых (TG-обогащённых).
- **Outreach 261 лидов**: enrich-contacts → рассылка с питчем «0% комиссии vs 30–67%».
- SEO: **фактоид-блок** (foundingYear+city) в карточке — презентация, без версионирования (не сделано).
  Факты в meta_description — только через RAG-генерацию (версионируется), НЕ bulk-UPDATE.
- SEO отклонено как неприоритет: Product+Offer JSON-LD и транзакц. title (мы brand-led, не product-led; нет товаров в выдаче).

---

## 2026-06-16 — Маркетинг/бренд-стратегия (из advisors)

Применение бренд-плейбука «как из скучных товаров делают культы» к продвижению самого
wearbase.ru. Полная стратегия: [`marketing_strategy.md`](marketing_strategy.md) (позиционирование,
враг, движение «Прямой бренд», мессединг) + каналы [`marketing_seo.md`](marketing_seo.md) и
[`marketing_email.md`](marketing_email.md). Исходный разбор-плейбук:
[`transcripts/branding-boring-products-theses.md`](transcripts/branding-boring-products-theses.md).

**Стержень:** враг = маркетплейс-«арендодатель» (бренд → безликий SKU, покупатель → лента
алгоритма); подписка/покупка = «голос» против него. Идентичность **+** математика (3000₽ vs
30-67%), не одно без другого.

**Роадмап (приоритет по убыванию; пока НЕ реализовано):**
- [ ] №1 [both, M] Движение «Прямой бренд»: манифест `/manifest` + бейдж-ассет для брендов.
- [ ] №2 [brand, Low] Рерайт копии /for-brands, /without-marketplaces, hero: фичелист → язык
      врага/идентичности (пиллары A-D из `marketing_strategy.md` §5).
- [ ] №3 [both, Low] On-page рефрейм каталога/карточек: H1/title/meta «напрямую, без
      маркетплейса» (`brand/index.html.twig`, `brand/show.html.twig` + `--meta-only`/`meta-repair`).
- [ ] №4 [brand, M] Калькулятор «сколько WB забирает у тебя» (shareable, ход Jolie).
- [ ] №5 [both, M] Enemy-pillar «уйти с маркетплейса» + 2 спойка, вплести в link-graph
      (`app:blog:publish-drafts` + `app:brand:build-link-graph`).
- [ ] №6 [both, S-M] GEO/FAQ-слой: сравнит. таблица 0% vs 30-67% + FAQPage + доп. Q в
      `app:brand:faq` («можно купить не на маркетплейсе?»). Без fake AggregateRating/Offer.
- [ ] №7 [shopper, Low] Рефрейм shopper-копии каталога: «нашёл раньше ленты» вместо «каталог 1000+».
- [ ] №8 [brand, M] ⚠️ ФЗ-38: двухстадийный email-гейт cold/warm + claim-CTA рерайт. Cold =
      уведомление «заберите страницу» БЕЗ цен; sales «3000₽ vs 30%» только после claim/opt-in.
- [ ] №9 [brand, M] Активационная серия free→paid: новая `app:activation:send` (Day 0/2/5/12/25)
      + калькулятор; метрика — free→paid по когорте + time-to-first-value.
- [ ] №10 [both, M] Newsletter→движение: редакц. слот + UTM (`app:newsletter:send-digest`);
      prereq для E-E-A-T-интервью — миграция `Article.author` + Article/Person JSON-LD
      (`src/Entity/Article.php` сейчас без поля автора — НЕ фабриковать авторов).

**Reality-check:** одной vitrine.market (263 лида → ~3-8 платящих) цель «40 брендов / 120к₽
MRR» не закрыть; нужен поток брендов (RAG) + B2C-спрос. Индексируется ~5.3% брендов —
enemy/культурный контент отрабатывает индексный бюджет (см. `marketing_seo.md` §0).

**Соц-блок (Instagram/Telegram/VK авто-постинг, drip).** План — [`marketing_instagram.md`](marketing_instagram.md).
⚠️ Meta/Instagram запрещена в РФ, платное продвижение нельзя (только органика) → движок
канал-агностичный, приоритет Telegram+VK, Instagram вторичен.
**✅ Реализовано (Ф1–Ф5, 2026-06-17), гибрид нативные TG/VK + Postiz для IG (см. marketing_instagram §9):**
- [x] [S] Статус-машина `social_post`/`social_channel`/`social_post_metric` + claim `FOR UPDATE SKIP LOCKED`.
- [x] [S] `app:social:plan` (сетка рубрик, MSK) + `app:social:generate` (подпись+медиа+QA, held при провале).
- [x] [S] Генерация подписей: шаблонный банк (ядро-сообщения, без LLM) + LLM из описания бренда; QA = banned-слова `ContentValidator`.
- [x] [S] Публикаторы TG (Bot API) / VK (wall.post) / IG (Postiz) через тег-реестр; `app:social:publish-tick` (рамп-предохранитель + 24ч-квота, ретраи, per-host egress).
- [x] [S] `app:social:evaluate` (read-only отчёт по рубрикам) + админка (каналы/посты, approve held). Рамп вынесен в `RampSchedule` + юнит-тест.

**Осталось (Ф0 внешнее + догенерация):**
- [ ] [prereq] Аккаунты: TG-канал (+бот-админ), VK-сообщество+токен, IG→Creator + Postiz self-host (egress к Meta!).
- [ ] [M] **Развернуть Postiz** (self-host) — только для IG (standalone Instagram-Login, без FB-страницы, §4а).
- [ ] [prereq] Сбор UGC/видео от подключённых брендов (Day-0 активационного письма + бейдж «Прямой бренд»).
- [ ] [M] **Метрик-коллектор** по площадкам → наполнение `social_post_metric` (включает closed-loop; авто-правка сетки рубрик).
- [ ] [S] VK photo-attach (сейчас текстовый wall.post); Postiz API-контракт под версию инстанса.
- [x] [S] **Image-gen — РЕАЛИЗОВАНО** в `MediaRenderer`: Gemini→Cloudflare→Pollinations (дефолт Cloudflare Flux, free, из РФ). UTM в CTA + per-platform рендер ссылки. ComfyUI на боксе off-peak — TODO (не нужно, картинки бесплатны в облаке).
- [ ] [M] **Faceless/data-Reels рендер** — **Revideo или Motion Canvas (MIT, free)**, детерминированный рендер из данных, без AI/GPU. (НЕ Remotion — BUSL-лицензия.) → апгрейд рубрик в ✅ полный авто.
- [ ] [S] **Product-motion (image-to-video)** — локально **LTX-Video / WAN 2.2 5B** на выделенной карте; либо облако fal.ai (Seedance ~$0.03/с, Kling Turbo ~$0.07/с). WAN 14B (40–48GB) — только облако/мультиGPU. Полу-авто.
- [ ] [S] **Audio-policy + AI-маркировка**: лицензионное/royalty-free аудио, метка AI-контента (Meta) в QA-гейте; финальная сборка Reels со звуком — semi-auto.
- ⚠️ Граница: НЕ генерировать синтетический «UGC»/founder talking-head — противоречит позиционированию «Прямой бренд» + нарушает маркировку Meta. AI только ассистирует реальным брендам.

---

## 2026-06-21 — Автоскейл RAG, dead-в-стадии, соц-fallback + адаптивные картинки

**Автоскейл / демон:**
- `app:rag:daemon` — `--shard/--total` (lock учитывает shard) → параллельные шарды стадии.
- `app:rag:autoscale` (cron `*/3`, Mac) — супервизор + target-tracking: держит 1 baseline-net
  (`discover,crawl,fetch,logo,push`) + 1 baseline-gpu (`embed:200,generate,faq,extract`, поднимается
  ТОЛЬКО при живом .119 — иначе net-only, не жжём attempts). На заторе fetch — burst-шарды по глубине
  очереди, cap по ядрам. Реконсайл = масштаб + респаун (отдельный супервизор не нужен). health-gate:
  варнинг, если embed/generate-очередь копится при мёртвом .119.
- ⚠️ Раздельные net/gpu baseline — чтобы GPU не голодал, деля цикл с медленными net-стадиями.
  `enrich` (OpenRouter, сеть) перенесён в net, не gpu (тормозил embed); `embed:200` — мелкая модель давит backlog.

**Dead-бренды (оценка в стадии fetch, без отдельной команды):**
- `finalizeIfDrained`: осушили очередь бренда + 0 корпуса → pipeline `dead` (терминал, исключён из всех
  стадий, не гоняем через embed→generate→deferred впустую). Новый статус `BrandRagPipeline::STATUS_DEAD`.

**Discover отключён (Yandex Search API — платный):** убран из autoscale baseline/burst (вернуть —
раскомментировать в `RagAutoscaleCommand`). keywords (Wordstat) — отдельный Yandex-биллинг, тоже не гонять.

**/admin/rag/flow:** панель живых процессов (демоны+стадии + счётчик); второе число «работы» у fetch (URL)
и embed (док) — отличать бренды-в-очереди от единиц работы; `dead` — отдельный исход.

**Соцсети:**
- `SocialPlanner`: fallback — в дни только с held-рубриками (UGC/lifestyle, не автогенерятся по политике)
  добавляется авто-template-пост (детерминированно по дате) → выходные не пустуют.
- `MediaRenderer`: случайный seed на каждую генерацию (Pollinations детерминирован по промпту → были
  одинаковые картинки на рубрику) + **адаптивный image-промпт**: caption → LLM (.119) → англо-промпт
  по содержанию поста → генератор; промежуточный промпт в `social_post.image_prompt` (для улучшения).

---

## 2026-06-20 — Диагностика пустых фетчей + reset мусорных источников в discover

Разбор «deferred ветка / source_count=0» (2366 брендов): URL помечены `fetched`, но
документов ~0 → корпуса нет → вечный deferred (очередь deferred не перебирает).

**Корень (не бан):** триаж 188/188 URL → http_status **NULL** (недоступны/DNS). Это
**мусорные/галлюцинированные домены** (NXDOMAIN: `ti.lr`, `bo.aw`, `wowahwul.hr`…) —
наследие инцидента SearXNG-CAPTCHA 2026-06-04..08, когда вспомогательный SearXNG отдавал
мусор. Re-fetch не спасает (домены мёртвые). Yandex уже первичный (`BrandSourceFinder`).

**Сделано:**
- [x] `brand_source_url.http_status` + `WebScraperService::fetchCleanTextWithStatus()` —
      фиксируем http-код фетча (триаж: 403/429 бан · 404 мёртв · 200 JS-пусто · null недоступен).
- [x] `fetch`: недоступный/мёртвый URL (null или ≥400) → **SKIPPED** (не FETCHED): в embed не
      попадёт (документа нет), fetch/crawl больше не берут (только pending), discover не дублирует
      (дедуп `findOneByBrandUrlHash` статус-агностичен → skipped остаётся, не реактивируется).
- [x] `app:brand:rediscover` (--id / батч deferred+source_count=0): мусорные URL → **skipped**
      (НЕ удаляем — soft, остаются для дедупа) + сброс pipeline в pre-discover (discovered_at=NULL,
      status=pending) → discover (Yandex) переоткрывает начисто. ⚠️ новую команду до `--no-debug`
      нужен `cache:clear` (no-debug контейнер кэширует список команд).
- [x] Прогон по бэклогу: **2353 бренда → очередь discover**, 5426 мусорных URL → skipped.

**Открыто / next:**
- [ ] Запустить discover-демон (Yandex) по 2353 переоткрытым → fetch→embed→generate. ⚠️ объём
      Yandex-запросов; демон с caps. Часть брендов получит реальный корпус, часть — честно тонкие.
- [ ] `app:brand:wb-enrich` (ингест товаров с Wildberries → корпус→embed→grounded) — отдельный
      рычаг для брендов с мёртвым own-site, но присутствием на ВБ (discover находит wildberries/ozon).
      Не в дефолтном цикле демона; запускать по `wb_status IS NULL` (active/new).

---

## 2026-06-20 — Конвейер: город + год основания в фактоид (кейс Bevza)

Кейс Bevza (627): украинский бренд, в БД city=«Москва», на проде 404, нет года/города/сегмента.
Разбор вскрыл несколько пробелов, не один баг.

**Диагноз:**
- Прод 404 — бренд доставлен, но `--publish` не довёл до active (остался new). Поэтому «пусто».
- `brand.city` ставился только импортом/ingest; RAG/extract писал geo в `brand_attribute`, но
  **не заполнял first-class `brand.city`/`brand.foundingYear`** → город застрял на импортном «Москва».
- **Год основания не реализован**: `setFoundingYear` не вызывался нигде (0/439), extract не извлекал,
  ассемблер не пушил. tasktracker:812 «фактоид — не сделано». Документированный, но невыполненный пункт.
- price_segment — извлекался и пушился, невидим только из-за 404.
- Корень для Bevza: crawl пропустил `/pages/about` (нет в sitemap) → факты (Киев, 2006) не в корпусе.

**Сделано (системно):**
- [x] `LlmService::extractBrandAttributes` извлекает `city` (город, не страна) + `founding_year` (4 цифры, grounded).
- [x] `ExtractBrandAttributesCommand` пишет `brand.city`/`brand.foundingYear`. City — **только если пуст**
      (LLM даёт разнобой «москва»/«московский» → перезапись фрагментировала бы city-хабы); год — всегда.
- [x] `BrandPayloadAssembler` + `BrandIngestService` — `foundingYear` в payload и приёме на проде.
- [x] Admin add-source форма (`RagDashboardController`): добавлены `own_page`/`product_sample`
      в выбор типа + `$allowed` + `tierForType` (tier 1). Раньше нельзя было вручную добавить own_page.
- [x] Bevza: about-страница затащена в корпус → extract дал **Киев · 2006**; city=Киев (вручную, факт),
      опубликован. Прод 200, фактоид «Основан в 2006 · Киев», JSON-LD foundingDate, geo/сегмент видны.

**Бэкфилл (сделано 2026-06-20):**
- [x] Новый флаг `app:brand:extract --fields-only` — backfill только `brand.city`/`foundingYear`,
      атрибуты не трогает (без `--force`-churn'а/дублей). ⚠️ batch-селектор `findForExtract` берёт только
      `attributesStatus IS NULL` → уже-обработанных не перебирает даже с `--force`; backfill — через `--id`-итерацию.
- [x] Прогон по 317 активным брендам с корпусом: **active с годом 0 → 82** (грунтовано, 1991–2021);
      пустые города заполнены. Доставлено на прод (done — обычный push; active+deferred/review — `push --id --force`).
      Проверено: foundingDate в JSON-LD + фактоид на проде.

**Открыто / next:**
- [ ] Бэкфилл остального каталога (new+done 2997 — не на проде, ценность ниже): `extract --id --fields-only` пачками.
- [ ] Не-РФ бренды (~20: 9 italia и т.д.) с неверным city — точечная курация (geo даёт страну, не город).
- [ ] crawl пропускает страницы вне sitemap (about) — рассмотреть добавление /about,/pages/about в кандидаты.

---

## 2026-06-19 — Плацдарм Москва: кураторский SEO-контент городских хабов (`CityHub`)

Big-player roadmap §#2 (выбор плацдарма): плацдарм = **Москва** (144 активных бренда — крупнейший
город каталога). Чтобы городской хаб мог быть уникальной топ-3-страницей, а не формульным дублем,
добавлен слот под кураторский контент.

**Что важно (опасение «перезатирания head-меты» снято):** `city.html.twig`/`style.html.twig` —
уже отдельные шаблоны со своими Twig-блоками `title`/`meta_description`/`og_*`; brand-мета живёт
в `brand/show.html.twig`. Общего head-блока нет → ничего не «течёт». Реальный пробел был в модели:
`brand.city` — строка (не FK к `City`, та для адресов), хранить SEO-контент города негде.

**Сделано (путь «расширить сущности», не новый шаблон):**
- [x] Сущность `CityHub` (`city_hub`): `slug` (unique) + `title`/`h1`/`metaTitle`/`metaDescription`/
      `intro` (HTML) + `isActive` + `Created`. Decoupled от `brand.city` и `City`; ключ — slug из
      `CitySlugger`. Миграция `Version20260619_city_hub` (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).
- [x] `cityShow` грузит `CityHubRepository::findActiveBySlug`; `city.html.twig` использует кураторские
      поля при наличии, иначе **прежняя формула** (проверено: `moskva` → кураторская мета/intro,
      `sankt-peterburg` → формула «58 марок»).
- [x] Засев Москвы (slug `moskva`): уникальные h1/meta/intro без фейк-цифр.
- [x] EasyAdmin `CityHubCrudController` (раздел «Контент») — контент городов редактируем, масштабируется
      на регионы. Стили (`BrandStyle` уже c `description`) — meta-поля добавим при выборе стиля-плацдарма.

**Покрытие Москвы (база 2026-06-19, 232 бренда):** done 127 (55%) · deferred 102 · review 3.
Из deferred: 45 own_site+sources (🟢 рескью), 9 sources-only, 48 без сайта/источников (🔴 тонкие).

**Сделано:**
- [x] Опубликован пул из **59 готовых (done+new)** через `app:brand:push --id=<59> --publish`
      (минуя дрип, осознанная концентрация плацдарма). Прод Москва **144→201 active**, 0 ошибок,
      IndexNow по каждому. Спот-чек прод-URL → 200.
- [x] Приоритет 102 deferred поднят до ≥50 (`brand_rag_pipeline.priority`) → демон берёт их первыми.

**Открыто / next по плацдарму:**
- [x] Задеплоено на прод (rsync + миграция `city_hub` + cache:clear). Смоук: `/ru/cities/moskva`
      отдаёт кураторский title/intro, `/ru/styles` 200, fallback СПб цел. ⚠️ rsync: `--exclude '.env.local*'`
      (канонический `--exclude .env.local` НЕ ловит `.env.local.bak-*` → утечка локального env на прод).
- [ ] §#2 рескью 102 deferred: `app:rag:daemon --no-debug` (discover→fetch→embed→generate, priority выставлен).
      Реалистичный потолок ~+50 → done ≈175–180; ~48 «мёртвых» без онлайн-присутствия исключить из знаменателя.
- [ ] §#3 топ-3 по «бренды одежды Москва» — после индексации (GSC).
- [ ] Засеять следующие города (СПб 58 / НН 13) тем же CityHub.

---

## 2026-06-19 — Публичные хабы стилей + веб-админ-доступ

Продолжение каноникализации стилей (`brand_attribute → brand_style`, M2M, коммит `7b860c8`):
у `BrandStyle` теперь есть `slug` (все 27 — заполнены), что разблокировало индексируемые
SEO-страницы по стилям (раньше «Стили» вели на якорь `home_hub#styles`).

**Публичные страницы стилей (`BrandsController`):**
- [x] `/{_locale}/styles` (`brand_styles`) — индекс всех непустых стилей (INNER join: только
      стили с активными брендами), карточки со счётчиком, JSON-LD `CollectionPage`+`BreadcrumbList`.
      Шаблон `templates/tailwind/styles.html.twig`.
- [x] `/{_locale}/style/{slug}` (`brand_style`, slug `[a-z0-9-]+`) — бренды одного стиля,
      JSON-LD `CollectionPage`+`BreadcrumbList`+`ItemList` (топ-30). Опц. `style.description` в интро.
      Шаблон `templates/tailwind/style.html.twig`.
- [x] Навигация/футер (`base.html.twig`) и блок на главной (`hub.html.twig`) → ведут на `brand_styles`;
      `topStyles` и плитки главной линкуют на `brand_style` по slug.
- [x] Sitemap (`SitemapController`): индекс стилей + по URL на каждый непустой стиль (priority 0.7,
      weekly). Пустые стили не индексируем.

**Веб-админ-доступ (новое, `AdminAccess` + `AdminAccessExtension`):**
- [x] `AdminAccess::isAdmin()` — единая проверка: main `ROLE_ADMIN` ИЛИ залогиненная admincore-сессия
      (`_security_admin`, читаем только при `hasPreviousSession()` — чтобы не плодить Set-Cookie/срывать
      кеш на анон-запросах). Twig: `is_platform_admin()`.
- [x] Админу — превью неопубликованных брендов на `brand_show` (раньше 404).
- [x] `POST /{_locale}/brands/{slug}/hide` (`brand_hide`) — веб-кнопка «🚫 Скрыть бренд» прямо на
      странице бренда, CSRF-гейт, через `BrandUnpublisher::hide` (soft-hide `Disabled` + снятие с прода).
      **Замена сломанной TG-кнопки** (вебхук Telegram→прод таймаутит).
