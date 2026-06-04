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