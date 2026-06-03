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

## Wordstat — АКТИВИРОВАН (коммит 062db00)
- [x] Реальный API выяснен эмпирически: `POST /v2/wordstat/topRequests`, `Authorization: Api-Key`, **folderId НЕ нужен**. Ответ: results(origin)+associations(related), count=показов/мес.
- [x] `WordstatClient` под реальный контракт; `WORDSTAT_API_KEY` в .env.local
- [x] Фильтр релевантности (имя бренда ИЛИ fashion-термин) — чинит мусор омонимов (SYNOPTIC→синупрет отсеяно)
- [x] Проверено: 185 ключевиков на 8 брендов
- [ ] (опц.) использовать ещё `regions` (affinity>130 — региональный спрос) и `dynamics` (тренды) из того же API

## НЕ СДЕЛАНО / TODO
- [ ] **Боевой прогон scrape на сервере** (там trafilatura): задать `TRAFILATURA_BIN` в серверном `.env.local`; на Mac сейчас fallback DomCrawler
- [ ] **Реальный батч**: прогнать N брендов без `--dry-run`, глазами проверить качество grounded-описаний, потом масштабировать шардами
- [ ] **Очередь + воркеры на LLM-сервере** (приоритет): скрейпер ДОЛЖЕН запускаться на сервере 192.168.2.43 (там trafilatura/ollama/Qdrant/SearXNG). Воркер ходит в очередь, берёт задания, выполняет, отдаёт результаты. Заменяет ручной `--shard/--total`. Архитектуру (Symfony Messenger / транспорт / топология воркеров / доступ к БД / деплой systemd-консьюмеров) — продумать отдельно (см. ниже «Архитектура очереди»)
- [ ] **Оркестрация/cron** боевого флоу: порядок scrape→keywords→embed→generate, расписание, периодический refresh (re-обход по TTL — сейчас findForScrape берёт только pending/failed)
- [ ] **Удалить Perplexity-ветку** в LlmService::researchBrandContacts после валидации локального пути
- [ ] **PHPUnit-тесты** новых сервисов (UrlFilter, TextChunker, BrandRagService gate, WordstatClient-парсинг) — отсутствуют
- [ ] **Валидация релевантности источников (дизамбигуация брендов-омонимов)** — ПРИОРИТЕТ. Текущий фильтр «имя бренда в title/snippet» слаб: для `MariDeniz` первый результат Яндекса — про диабет, только второй про бренд (ya.ru/search/?text=MariDeniz). Скрейпер может взять не тот сайт → ложные факты в RAG. Нужно валидировать, что страница вправду относится к ЭТОМУ бренду одежды:
  - усилить фильтр: требовать fashion-контекст (одежда/коллекция/магазин/streetwear…) рядом с именем, а не только имя;
  - LLM-классификатор по сниппету/первому экрану: «это страница бренда одежды X? да/нет» перед принятием источника;
  - помечать сомнительные источники (low-confidence) и не заземлять на них генерацию (гейтить как retrieval-gate);
  - на бренд: проверять, что найденный официальный сайт действительно fashion (а не однофамилец/омоним).
- [ ] **Валидация результатов поиска контактов** — та же дизамбигуация для enrich: найденные website/email/соцсети должны принадлежать бренду одежды, а не омониму (MariDeniz→диабет). Сейчас confidence ставит LLM по тексту; добавить fashion-проверку источника контактов + перепроверку, что домен/соцсеть про одежду, прежде чем сохранять в Brand/BrandLink.
- [ ] **Тюнинг качества**: gate MIN_SCORE/MIN_CHUNKS, top-k, размер чанка, multi-aspect аспекты — по результатам реального прогона
- [ ] **Перепроверить bge-m3** при апдейте ollama (ушли на qwen3-embedding из-за NaN в ollama 0.22)
- [ ] **SearXNG надёжность**: движки могут rate-limit'ить → пустые выдачи; мониторинг/ретраи
- [ ] **CLAUDE.md**: дополнить таблицы команд/сервисов RAG-частью
- [ ] (minor) URL-нормализация кеша для доков, сохранённых до rtrim-правки (новые консистентны)

## Архитектура очереди (дизайн от агента-архитектора)

**Центральный факт:** БД (MySQL) сейчас на Mac (`DATABASE_HOST=127.0.0.1`), GPU-сервис на 192.168.2.43 — **у сервера нет доступа к БД**. Это «петля» всего дизайна.

**Решение (рекоменд.):** Symfony Messenger + **Doctrine-транспорт** (уже установлен; `messenger_messages` в MySQL, `SKIP LOCKED`). Транспорта три: `scrape` (IO, 4-8 воркеров), `keywords` (rate-limited, 1-2), `gpu` (embed+generate+enrich, **суммарно ≤ OLLAMA_NUM_PARALLEL=4** — общий GPU!). Failure-транспорт `failed`, retry exponential. Middleware `doctrine_ping/close_connection` вместо ручного resetManager.

**Доступ к БД (открытый вопрос — решить ПЕРВЫМ):**
- (a, реком.) **Tailscale** Mac↔сервер → серверный `.env.local` `DATABASE_HOST=<mac-tailscale-ip>`, воркер пишет напрямую через Doctrine (код не меняется). Или перенести MySQL на сервер (тогда всё localhost).
- (b, fallback) сервер без БД → транспорт Redis на сервере + result-очередь, консьюмер-писатель на Mac. Сильно больше кода.

**Сообщения/хендлеры:** вынести `processBrand` каждой команды в headless-сервис `src/Service/Rag/Brand*Processor.php` (один код — два входа: команда + хендлер). Сообщения `ScrapeBrand/EmbedBrand/GenerateBrandContent/CollectKeywords/EnrichBrandContacts` (только brandId+флаги). **Цепочка** (scrape→dispatch embed→dispatch generate) для латентности + **диспетчер-cron** `app:rag:dispatch` для посева/backstop (через finders, дедуп через новые `*QueuedAt` колонки).

**Воркеры:** systemd-шаблон `wearbase-consumer@.service` (`messenger:consume <transport> --limit=200 --time-limit=3600 --memory-limit=512M`, Restart=always). Заменяет `--shard/--total`.

**Ретраи:** Messenger = транзиентные сбои (throw → retry); статус-машина `*_failed`+attempts = ТЕРМИНАЛЬНЫЕ (через `WorkerMessageFailedEvent` subscriber, `!willRetry()`), чтобы не двойного счёта.

**Миграция (по шагам, каждый shippable):** 1) extract processors; 2) messages+handlers+messenger.yaml; 3) `app:rag:dispatch`+`*QueuedAt`; 4) Tailscale+деплой на сервер; 5) terminal-failure subscriber+chain; 6) systemd-консьюмеры, низкие counts→масштаб.

**Риски:** латентность БД с сервера (round-trips per brand — ок при текущем темпе); GPU-thrashing embed↔generate (27b ~16-20ГБ — проверить, влезают ли обе модели; иначе разнести `gpu_embed`/`gpu_generate`); bloat `messenger_messages` (cron `messenger:failed:remove`); утечки памяти консьюмеров (обязательны `--limit/--time-limit/--memory-limit`).

Файлы: `config/packages/messenger.yaml`, новые `src/Service/Rag/`, `src/Message/`, `src/MessageHandler/`, `src/EventSubscriber/`, `src/Command/DispatchRagJobsCommand.php`, `BrandRagPipeline` (+`*QueuedAt`), `BrandRepository` (дедуп-clause).
```