# WEARBASE SEO Implementation Plan

**Обновлено:** 2026-05-14
**Статус:** В РАБОТЕ

---

> ⚠️ **ОТМЕНЕНО 2026-07-31**: директива «первая продажа» снята владельцем; новая директива фокуса —
> **рост аудитории Instagram** (см. раздел «2026-07-31…08-01 — IG-спринт» внизу файла и память
> focus-directive-ig-audience). Заморозка витрины FR / гардероба / VTON остаётся в силе.
>
> <details><summary>Историческая директива 2026-07-19 (отменена)</summary>
>
> ⚠️ **Директива фокуса 2026-07-19** (разбор Ключарёва → [klyucharev_decisions_2026.md](klyucharev_decisions_2026.md)):
> нейробиология подтвердила — проект в ловушке «постройка машины без обратной связи» (dev 6728 new / active 439, монетизация 0). **WIP=1**: главное необратимое действие недели — **первая продажа** (20 холодных писем по `sales_offer.md` брендам из `_docs/cold-sales-candidates.csv`). **Заморожены** до первого платящего бренда: витрина FR, семейный гардероб, VTON, платёжки кроме yookassa, advisor-фазы 2–5. Активно: Phase-1 дайджест + дрип-публикация очереди (факт 2026-07-19: eligible-очередь ~2119, дрип экспоненциальный 27→33/день после деплоя ramp 22%, cap 80 — см. PublishTickCommand).
>
> </details>

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
5. ~~**GenerateBrandContentCommand:425** ещё режет metaTitle до 60~~ ✅ ЗАКРЫТО (2026-06-24): жёсткого обреза нет — title идёт через `SeoMetaService::fitTitleForRender()` (word-boundary трим, render-safe ≤60 с учётом ` | WEARBASE`). Пункт про «48» устарел (двойной хвост решён иначе).
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

**Открыто:** падения PHPUnit. Прогресс 2026-06-24: убраны 2 ложных **ERROR** «the kernel should only be booted once» (проба БД в `setUpBeforeClass` бутила kernel и не гасила его) — добавлен `ensureKernelShutdown()`; DRY-рефактор: проба БД + skip-guard вынесены в базовый `tests/Controller/DatabaseDependentWebTestCase.php` (Account/BrandLk наследуют, 7 инлайн-guard'ов BrandLk → один `skipIfNoDatabase()`). Suite теперь доходит до конца без OOM/ERROR: **0 errors, 26 failures**. Остаток (26) — единый корень: `loginUser()` на non-persisted `UserFactory`-стабе → `EntityUserProvider` не может refresh'нуть юзера без id; нужна персистенция тест-юзера в `*_test` БД + очистка (отдельная инфра-задача, не правка одной строки). Instagram-ссылка в подвале — решить про дисклеймер Meta.

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
**✅ Реализовано (Ф1–Ф5, 2026-06-17), нативные паблишеры TG/VK/IG — без Postiz (см. marketing_instagram §9; IG отгружен 2026-07-18):**
- [x] [S] Статус-машина `social_post`/`social_channel`/`social_post_metric` + claim `FOR UPDATE SKIP LOCKED`.
- [x] [S] `app:social:plan` (сетка рубрик, MSK) + `app:social:generate` (подпись+медиа+QA, held при провале).
- [x] [S] Генерация подписей: шаблонный банк (ядро-сообщения, без LLM) + LLM из описания бренда; QA = banned-слова `ContentValidator`.
- [x] [S] Публикаторы TG (Bot API) / VK (wall.post) / IG (`InstagramPublisher`, официальный Instagram API with Instagram Login, `graph.instagram.com`, без FB-Страницы и без Postiz) через тег-реестр; `app:social:publish-tick` (рамп-предохранитель + 24ч-квота, ретраи, per-host egress).
- [x] [S] `app:social:evaluate` (read-only отчёт по рубрикам) + админка (каналы/посты, approve held). Рамп вынесен в `RampSchedule` + юнит-тест.

**Осталось (догенерация + метрики; IG-аккаунт/публикация — сделано, см. 2026-07-18 ниже):**
- [x] [prereq] Аккаунты: TG-канал (+бот-админ), VK-сообщество+токен, IG→Creator + Instagram Login
      токен (**план «Postiz для IG» снят** — публикуем напрямую через Instagram API, без self-host).
- [ ] [prereq] Сбор UGC/видео от подключённых брендов (Day-0 активационного письма + бейдж «Прямой бренд»).
- [x] [M] **Метрик-коллектор — РЕАЛИЗОВАН** (`app:social:collect-metrics`, 2026-08-01, см. раздел «IG-спринт» внизу); авто-правка сетки рубрик — по-прежнему TODO (данных мало).
- [ ] [S] VK photo-attach (сейчас текстовый wall.post) — **на паузе**, нужен VK user-токен
      (community-токен не грузит фото на стену).
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
- [x] Засеять следующие города тем же CityHub. Формульный засев СПб/НН/Екб — `Version20260620_city_hub_seed`
      (INSERT IGNORE). **2026-07-10** обогащены СПб (63 active) и НН (13 active) кураторским intro/meta
      с именами реальных локальных марок из базы (СПб — Krakatau/Gate31/SHU; НН — Called a Garment/Ruff Global):
      миграция `Version20260710_city_hub_local_brands` (идемпотентный UPDATE по slug). Смоук на dev — 200, intro/meta ок.

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

---

## 2026-06-25 — Ниша-гейт брендов + HTTP-семантика (410/tombstone)

Повод: `apadent` 404 на проде → оказалось, это японская **зубная паста**, случайно затянутая
импортом лидов в каталог одежды. Аудит: ≥759 из 6724 `new` и ≥29 `active` брендов — не из ниши
(аптека «Апрель» 755k показов, пылесосы, смартфоны, матрасы). Полный reference —
[brand_lifecycle.md](brand_lifecycle.md). Ветка `task-20`.

**Сделано:**
- [x] Миграция `Version20260624_brand_lifecycle`: `brand.niche_status/niche_reason/niche_checked_at`
      + `closed_at` (+ индекс). Хелперы на `Brand`: `isOffNiche/markNiche/isClosed/close/reopen`.
- [x] `app:brand:niche-check` — классификатор ниши (мода+красота = `in`): фаст-путь по маркерам +
      локальная LLM (JSON). Недеструктивен; `--set=in|off|closed|reopen|delete` (с `--id`).
- [x] Гейт `off`: единая точка `PipelineQueueRepository::finishStageQuery` (весь конвейер) +
      raw-SQL в `PublishTickCommand` (дрип). `NULL`/`in` проходят.
- [x] HTTP в `BrandsController::show()`: `deleted`→410 (`gone.html.twig`, noindex), `active`+`closed_at`
      →200+tombstone-плашка, `new`/`disabled`→404. Тест `NicheLifecycleControllerTest` (3 кейса) зелёный.
- [x] Доки: `brand_lifecycle.md` + индекс + `commands.md` (cron-порядок).

**Открыто / next:**
- [ ] **Бэкафилл всего каталога** (когда дойдут руки): `php -d memory_limit=512M bin/console
      app:brand:niche-check 7000 --no-debug`. ~7k LLM-вызовов на локальном сервере (IP непостоянен) —
      долго; фаз-путь по маркерам срежет часть. Сначала разгрести живые: `--status=active 500`.
- [ ] Ручное ревью списка **active-off-niche** из вывода → решить `--set=delete`/оставить (29 живых в индексе).
- [ ] Закоммитить ветку `task-20` (изменения готовы, но не закоммичены).
- [ ] Прогнать `niche-check` в cron **перед** `publish-tick` (порядок важен — гейт пропускает непроверённые).

---

## 2026-06-28 — Аудит размещения этапов конвейера (9 агентов-архитекторов) + баг гонки keywords

Повод: вынос RAG-конвейера в отдельный проект **seo-factory** (вертикальная хореография — каждый этап
независимый воркер, дренаж status-очередей). По одному агенту-архитектору на этап: отделяли
*реальную зависимость данных* (что команда читает) от *места в оркестрации* (на каком статусе дёргается).
Полный разбор — в памяти `seo-factory-pipeline-architecture`. Триггер-кейс: `extract` казался поздним
(после `done`), но читает `clean_text` напрямую → зависит только от `scraped`.

**Что в wearbase реально сломано vs нет (проверено по `RagDaemonCommand`/`PipelineQueueRepository`):**
- ✅ `logo` НЕ сломан — `STAGES` гонит его ДО generate (стр.47), finder не гейтит на `done`.
- ✅ `extract` НЕ сломан — `findForExtract` уже принимает `STATUS_SCRAPED` (+EMBEDDED/DONE/DEFERRED),
      триггерится с корпуса. (Первый Explore-агент ошибся, не дочитав finder.)
- ✅ push re-delivery работает — extract (ATTR_DONE) и logo (`markContentChanged`) бампают
      `contentChangedAt`, push переотправляет по `contentChangedAt > pushedAt`.

**🔴 Единственный реальный баг — гонка keywords (correctness, тихий):**
`generate-content` вплетает Wordstat-фразы в title/description/meta (`rankedKeywords`, `LlmService:142-144`),
но `keywords` — отдельный медленный демон (`STAGES:52`, не в основном цикле, квота ≤97/час). Основной цикл
доводит бренд до `done` задолго до keywords → контент цементируется БЕЗ ключей, авто-регена нет.
Показательно: `faq` от этого защищён (`findForFaq` требует `keywordsStatus IS NOT NULL`), а `findForGeneration` — нет.

**Открыто / next:**
- [ ] **Замерить масштаб порчи:** `SELECT COUNT(*) FROM brand_rag_pipeline p JOIN brand_keyword k ON
      k.brand_id=p.brand_id WHERE p.status='done' AND p.generated_at < k.created_at;`
- [ ] **Фикс self-heal (~4 строки):** в `CollectBrandKeywordsCommand` (~стр.188, у `setKeywordsStatus`) —
      если `status==KW_FOUND && pipeline.status==STATUS_DONE` → `setRegenRequestedAt(now)`. Реген-машинерия
      уже есть: `findRegenFlagged` (`PipelineQueueRepository:41`) → `generate-content --regen-flagged`
      (сбрасывает флаг). Сейчас флаг ставит только `EvaluateExperimentsCommand`.
- [ ] Добавить периодический `generate-content --regen-flagged` в демон (отдельной стадией / после keywords-батча).
- [ ] НЕ делать жёсткий гейт (зеркало `faq`: `keywordsStatus IS NOT NULL` в `findForGeneration`) — посадит
      throughput всего контента на квоту Wordstat ≤97/час.
- [ ] Разово прогнать `--regen-flagged` по бэклогу испорченных после фикса.
- [ ] **Операционный:** `OLLAMA_NUM_PARALLEL=4→1` в systemd на LLM-сервере — снимает краш gemma
      (оверсабскрипшн) уже сейчас, до seo-factory.

**Для seo-factory (не баги wearbase, на будущее):** дубль предиката готовности ×3
(`isPublishReady`/`readyToPushQb`/`--id`) → 1 спека; `findForGeneration` без `SKIP LOCKED` (для верт.воркеров);
embed делит ollama с gemma (`keep_alive=30m` hack) → решается пиннингом моделей по картам.

---

## 2026-06-28 — AI-сдвиг Google: разбор влияния + click-трекинг + чистка JSON-LD/FAQ

Повод: транскрипт видео Rush Agency (Google I/O 19 мая: AI Mode >1 млрд MAU + May Core Update) →
многоагентный разбор (12 агентов: инвентаризация поверхностей → оценка по 6 осям тезисов видео → синтез).
Полный анализ — **[ai_search_impact.md](ai_search_impact.md)**. Вердикт: **moderate-risk с наполовину
готовым моатом** — grounded-RAG защищает (97% брендов `grounded=1`, AI-выдача не заменяет first-party
факты), недозащищены click-трекинг и generic-слой (boilerplate-FAQ / тонкие заглушки / фейк-рейтинги
листиклов). Нюанс: видео ПЕРЕоценивает риск для одежды (визуально-транзакционный интент плохо закрывается
текстовым AI-ответом) и НЕДОоценивает устойчивость бренд-навигации.

**Сделано (ветка task-20):**
- [x] **Click-трекинг исходящих переходов `/go/{id}`** (коммит 52cb428) — закрывает приоритет №1 из
      `global_analogs.md`. Редирект-прокладка: цель из БД (open-redirect невозможен), боты не учитываются,
      `brand.outbound_click_count` + append-only лог `brand_outbound_click` (+ `topBrands`/`countForBrand`).
      По образцу `OutreachController` (stateless, нативный SQL, rate-limit, `X-Robots-Tag: noindex` +
      `Referrer-Policy: no-referrer`, `ua_hash` без PII). Все исходящие ссылки бренда (links-партиал, CTA,
      товарная карточка) через прокладку; `Disallow: /go/` в robots. E2E-проверено (302 + учёт; бот → без учёта).
- [x] **Убрана boilerplate-FAQPage из JSON-LD** карточки бренда (коммит ffa4624) — риск №2. FAQPage в `@graph`
      теперь только при непустом `brand_faq` (раньше при пустом лились 4 шаблонных Q&A на ~4300 страниц =
      spam-structured-data + zero-click). Видимый HTML-аккордеон оставлен без schema. Проверено: с FAQ →
      реальный FAQPage; без → узла нет; JSON-LD валиден в обоих случаях.
- [x] **Чистка тест-мусора в `brand_faq`** (коммит c87ff8d) — миграция удалила «Что это? → Тест агент-API.»
      на фикстур-бренде `test-ingest-brand`. Скоуп по slug; `brightest` (реальный бренд, «test» — случайная
      подстрока) не тронут. 12918→12917, маркер 0.

**Открыто / next (из `ai_search_impact.md`, по убыванию приоритета):**
- [ ] **Деиндексация тонких/`grounded=0` карточек** (quick-win №3): `noindex,follow` при `descLen<400` +
      убрать из sitemap до дозревания RAG (`base.html.twig` + `SitemapController:166-181`).
- [ ] Чинить `Org.url`/`sameAs` = `brand.links|first` → `own_site` детерминированно; добавить Instagram в `sameAs`.
- [ ] Обрезать `anons` по границе слова (`GenerateBrandContentCommand:471`).
- [ ] GSC-сегментация по типу страницы: позиция-vs-CTR (отделить Core Update от AI Mode).
- [ ] Сдвиг индексного дрипа с по-дате на по-ценности (потолок ~5.3%, 2389 grounded ждут).
- [ ] **Стратегия:** собственные первичные данные (цены/наличие/deep-link на товар как Lyst/Musinsa);
      переформат листиклов из фейк-рейтингов «ТОП-N 2026» в честные гиды + anti-fabrication судья на внешние
      площадки (vc/dtf/pikabu сейчас без судьи → риск Manual Action); бренд-спрос/упоминания.
- [ ] **Связанная находка:** фикстур-бренд `test-ingest-brand` (status=new, публично 404) остаётся в каталоге;
      проверить, не пишет ли агент-API-тест в реальную БД (корень происхождения мусора).

---

## 2026-06-28 — E-E-A-T авторство + доставка country + фиксы конвейера + Brave-источник

Коммиты в `task-20` (запушено): `4c82399` авторство/схемы, `6d63145` country+extract,
`414f259` фиксы конвейера, `863d116` метеринг источников поиска.

**✅ E-E-A-T авторство (живо на проде):** сущность `Author` + Анна Семянникова (автор-куратор), страница
`/ru/author/{slug}` (Person JSON-LD: alumniOf Школа шопинга, sameAs Instagram) + индекс `/ru/author`,
байлайн в блоге, EasyAdmin CRUD «Авторы» + поле автора в карточке статьи, авторы в sitemap. Из генераторов
убрана **фейк-персона** в JSON-LD. Схема статей: `Article`+author даёт шаблон (реальный Person), а
`ItemList(+FAQPage)` **цементируется в content** (генераторы site → graph без Article). Память:
`author-eeat-implementation`, `blog-publish-to-prod`. ⚠️ rsync фото с правами **644** (иначе 403).

**✅ Доставка country на прод:** `BrandPayloadAssembler`+`BrandIngestService` шлют/принимают `country`;
`extract --published-missing`/`--ids=CSV` — бэкафилл city/country у живых брендов. country на проде растёт.

**🔴→✅ Инцидент «конвейер встал» (3 причины, все исправлены, коммит 414f259):**
- `getOrCreate` 1062: `findOneBy` не видел persisted-но-не-flushed строку → 2-й INSERT в юните →
  `uniq_brand_rag_brand` → откат батча discover. Фикс: проверка `getScheduledEntityInsertions`.
- `findForWbEnrich` брал `p.id IS NULL` (создавал строки новым брендам) → гонка с discover за brand_id.
  Фикс: `innerJoin` (только открытые).
- burn-loop генерации: провал `validateDescription` оставлял бренд в `embedded` → `findForGeneration`
  перевыбирал каждый цикл, жёг gemma. Фикс: → `generate_failed`+инкремент.
- ⚠️ грабли: **`cache:clear` при живых демонах ломает их дочерние процессы** (`Failed opening required
  ContainerXXX.php`) → конвейер встаёт. Правило: после `cache:clear`/деплоя — рестарт демонов. Память
  `llm-server-oversubscription`. Ловушка диагностики: очередь fetch = `brand_source_url.status`, НЕ `http_status`.

**✅ Источники поиска (метеринг/cap, коммит 863d116):** Yandex Search API стал платным → `YandexSearchMeter`
(дневной cap, fail-closed) + панель на дашборде + `api_usage_daily`. **Brave API** как бесплатный
fallback-добор (1000/мес, `BraveSearchMeter` месячный cap; зовётся только когда основные дали < N).
discover throttle + джиттер (антибан). google в SEARX_ENGINES включён. Инструмент **`bin/captcha-proxy.sh`**
— решать капчу Google для SearXNG через winproxy-egress (память `llm-ollama-server`).

**Открыто / next:**
- [x] **WB-enrich — выключен НАМЕРЕННО (28.06): WB блокирует скрейп.** Побочная стадия, ничего не гейтит
      (`wb_status` не смотрит ни один finder). `search.wb.ru` режет ботов: Mac→WB **HTTP 429**, мобильный
      winproxy-egress (92.36.x) → пусто/блок. Обход (ротация резидентных прокси + фингерпринт) — арм-рейс,
      ради побочной стадии не стоит. Код готов и в репо (`6c5e262`): `findForWbEnrich` сужен до брендов с
      wildberries-ссылкой (~1832), стадия `wb`=`app:brand:wb-enrich` в карте демона, но НЕ в net-наборе.
      Включать `php bin/console app:brand:wb-enrich N`, ЕСЛИ появится рабочий доступ (офиц./партнёрский WB-API).
      3 бренда `wb_status=error` — терминальны (finder берёт только NULL).
- [ ] **country у ~4240 done-брендов:** `extract --published-missing` покрыл живые; для остальных done с пустым
      `country` нужен `extract --fields-only --force` (дороже по GPU). Когда понадобится фильтр иностранных шире.
- [ ] **~12 отбракованных gate'ом ячеек** SEO-статей (casual/СПб и др., «лид-блок не самодостаточен» 3/3) — догенерить.
- [ ] **«О проекте»** с владельцем-основателем (Trust площадки, E-E-A-T) — отложено.
- [ ] Brave-панель на RAG-дашборд (как Yandex) — опц., по аналогии.
- [ ] Wordstat-ключ пересоздать (Yandex Cloud) при возврате к платным ключевикам (память `wordstat-dead-key-gotcha`).

---

## Сессия 01.07 — GEO под ChatGPT + дашборд кликов

Повод: запрос в ChatGPT «российские бренды одежды» → разложить его ответ по нашему каталогу и «подготовиться под него».

**✅ Дашборд исходящих кликов** — `/admin/clicks` (`ClickDashboardController` + `admin/click_dashboard.html.twig`,
пункт меню «Клики по брендам»). KPI по окнам (7/30/90/365), график по дням, топ-50 брендов, разбивка по типу
ссылки и хостам (поверх `BrandOutboundClickRepository::topBrands`). На проде.

**✅ Бренды из ответа ChatGPT** (22 имени сверены с каталогом):
- 6 grounded-флагманов были 404 → адресно опубликованы (`push --id --publish`, минуя дрип): LIME, LOVE REPUBLIC,
  2MOOD, You Wanna, Alexander Terekhov, Present & Simple.
- 3 тонких через RAG (discover→embed→generate): **Befree**, **Walk of Shame** → grounded + опубликованы.
  **Gloria Jeans** — застряла (8 источников < gate; WB-enrich упал по таймауту WB API) → TODO отдельный сбор.

**✅ 4 статьи блога (курированы под бренды ChatGPT)** — `var/seo/blog-chatgpt/`, опубликованы дрипом на проде:
хаб «Российские бренды одежды 2026» (5 сегментов + перелинковка) · Премиум/Люкс (4 люкс-имени) · Минимализм ·
Уличный стиль. Добавлен флаг **`app:seo:guide --brands=slug1,slug2`** (курированный список вместо спросового
топа — иначе в «российский» гид лез амстердамский A-dam).

**✅ CLI memory_limit** — Homebrew дефолт 128M мал для `cache:clear` (Twig-варммер, пик ~140M) →
`/opt/homebrew/etc/php/8.4/conf.d/zz-cli-memory.ini` = 512M (вне репо).

**Открыто:** Gloria Jeans (сбор источников) · дрип-даты минимализм 07-09 / streetwear 07-12 (унаследованы от старых
`-site`-версий, можно сдвинуть) · Wordstat-ключ протух (блокирует FAQ/keywords).

### Продолжение 01.07 — тот же запрос от Алисы (Яндекс) + правило про --force

Повод: тот же вопрос «российские бренды одежды», но от **Алисы**. Отличие от ChatGPT — Алиса **цитирует источники**
(halvacard/lenta/**dzen**/market.yandex.journal/rbc/lamoda) → **Dzen (площадка Яндекса) = прямой канал в цитирование**.

**✅ Бренды Алисы** (13 новых имён сверх ChatGPT): опубликованы 7 grounded (Oodji, Aim Clo, Ushatáva, Monochrome,
COCOS, DIVNO, Lesyanebo) + **BLCV** через полный RAG. Уже были живы: Namelazz, Alena Akhmadullina, Yanina Couture,
Vika Gazinskaya, ТВОЕ. Gloria Jeans — по-прежнему блок WB.

**✅ Статьи:** женский хаб «Российские женские бренды одежды 2026» (blog, новый slug) + дизайнерский гид «Авангард»
(Dzen; blog-версия ПРОПУЩЕНА — на проде уже был `avangard-...`, сохранён). Dzen-файлы для ручного постинга:
`var/seo/dzen/guide-avantgarde-dzen.md`, `var/seo/dzen/hub-zhenskie-rossijskie-brendy-2026.md`.

**⚠️ Правило (обратная связь владельца):** готовый опубликованный контент **НЕ перезаписывать `--force`** при
совпадении slug. Новый повод → новый slug. (Память `no-force-overwrite-ready-articles`.) По этому правилу:
minimalism/streetwear — **оригиналы восстановлены** (`-site`, slug `minimalizm-gid-…`/`ulichnyi-stil-…`), а
курированные версии размещены под **новыми** slug (`rossiiskii-minimalizm-brendy-osoznannogo-garderoba-2026`,
`rossiiskii-stritvir-brendy-ulichnoi-mody-2026`) — обе версии сосуществуют.

**Инфра:** `.env` пропадал при переключении веток (удалён из git в `41ae581`) → восстановлен из истории (untracked,
секреты в `.env.local`). Осторожно с checkout на коммиты до `41ae581`.

### 01.07 — GSC: заморозка покрытия индекса + пробел по Яндекс.Вебмастеру

**Проверка интеграции GSC — здорова:** OAuth `authorized_user` (refresh_token, `config/secrets/gsc-sa.json`),
`sc-domain:wearbase.ru`, синк по расписанию (`app:gsc:sync --report` 08:00 + `app:google:index-ping` 11:00,
через мастер-крон `app:cron:run-scheduled`). Живой тест: тянет Search Analytics ОК.

**⚠️ Индексация в GSC заморожена ~с 12.06.2026 (сторона Google, не наша).** Подтверждено данными:
`first_indexed_at` даёт пик 12.06, дальше резкий спад и после 24.06 — ноль. В UI GSC у владельца та же картина
(похоже на большой апдейт Google). **Два потока GSC обновляются независимо:**
- **Index Coverage / URL Inspection** (`gsc_index_status`: coverage_state, first_indexed_at) — **заморожен с 12.06**.
- **Search Analytics** (`gsc_page_stats`: показы/клики) — **живой** (данные до 29.06, синк идёт).

Следствия: цифра «~5% в индексе / 21 проиндексировано» — это **замороженный снимок от 12.06**, не текущая реальность;
всё, что опубликовано после 12.06 (в т.ч. ~24 бренда сессии), в покрытии пока не отобразится. **Живой сигнал —
Search Analytics: 81 бренд с показами = проиндексированы.** Любой тормоз-дрипа правильнее вешать на показы
Search Analytics, а не на coverage (Google может заморозить). Пока — ждать разморозки Google; следить за
`first_indexed_at` в синках. Пинги Indexing API (11:00) продолжают подталкивать.

**⚠️ Пробел: Яндекс.Вебмастер (webmaster.yandex.ru) НЕ интегрирован.** По Google тянем поиск/индекс (GSC), по
Яндексу — только IndexNow-пинг при публикации + Search API/Wordstat (ключ выключен/протух). Нет аналога GSC для
Яндекса: индекс-покрытие, поисковые запросы/показы в Яндексе, статистика хоста. Учитывая, что Яндекс — приоритетный
рынок (Alice GEO, RU-доля) и что GSC сейчас заморожен, интеграция Yandex.Webmaster API (api.webmaster.yandex.net,
OAuth — по образцу `GscClient`) даст независимый и более релевантный сигнал индексации/спроса. TODO/оценить.

**Яндекс.Метрика — счётчик УЖЕ стоит, но данные не подтягиваются программно.** Counter ID `105219484`
(`templates/tailwind/_yandex_metrika.html.twig`, подключён в `base.html.twig` + `app.html.twig`) → поведение/трафик
копятся в metrika.yandex.ru, но в наши отчёты/гейты Метрика не заведена (нет API-клиента/env). NB различие:
**Метрика = поведение и трафик уже пришедших** (аналог GA), **Вебмастер = индексация + поисковые запросы/показы**
(аналог GSC) — для GEO/индекс-сигнала нужен именно Вебмастер, Метрика его не заменяет.

**Вариант быстрого подключения аналитики: официальный Yandex Metrika MCP** (видел в интернете) — подключить как
MCP-сервер и запрашивать Метрику (трафик/источники/конверсии) прямо из агента, без своей API-интеграции. Плюс —
быстро; минус — интерактивный OAuth (может не работать в headless/cron-прогонах, как и другие claude.ai-MCP).
Для автоматических гейтов/отчётов всё равно нужен серверный клиент; MCP — для ручного анализа.

**Сводка «что есть по поисковикам/аналитике»:**
- Google: GSC (индекс+запросы) ✅ · Indexing API пинг ✅ · GA — нет.
- Яндекс: IndexNow-пинг ✅ · Метрика-счётчик ✅ (без API) · Search API/Wordstat (ключ выключен/протух) · **Вебмастер — НЕТ** (главный пробел для индекс-сигнала по RU).

### 02.07 — исследование поиска Яндекс+Google (полный отчёт: docs/seo_yandex_google_research.md)

По данным Вебмастера + GSC. Главное: **спрос ~100% навигационный** (по имени бренда, discovery-запросов нет);
**CTR ~0.8%** — мы на позиции 9–13, ниже сайта бренда и маркетплейсов (~50 кликов/нед на оба движка);
**Яндекс индексирует 478 брендов против 81 в Google** (400 Яндекс-only; Google — бутылочное горлышко + заморозка
покрытия с 12.06); блог/GEO пока 0 показов. Быстрые победы: навигационные «дно стр.1» в Яндексе (yollo, yes yes
brand, cortisoljeans…) дожать в топ-5; Google — большие показы при 0 кликов (ostrovimenitebya 1650/0, aaasergeidesev).
Приоритет: выигрывать навигационный SERP как «лучший сторонний результат» (модули отзывы/где купить/аналоги) +
перелинковка навигационного трафика в discovery.

### 02.07 — карточка бренда: рефрейм навигационных модулей + БЭКЛОГ отзывов/товаров

Из исследования ПС (спрос ~100% навигационный, мы на поз.9–13 ниже сайта бренда/маркетплейсов).

**✅ Сделано (рефрейм, on-page, без риска):**
- «Похожие бренды» → **«Аналоги {бренд}»** (+ подзаголовок «чем можно заменить») — под запрос «аналоги {бренд}».
- `links.html.twig` разнесён на **«Где купить {бренд}»** (сайт + маркетплейсы WB/Ozon/Lamoda/AliExpress/Я.Маркет с иконкой корзины; wa.me→WhatsApp) и **«{бренд} в соцсетях»** — под «где купить {бренд}». Деградирует чисто (пустые блоки скрыты).

**🔖 БЭКЛОГ — отзывы о бренде** (модуль под запрос «{бренд} отзывы»):
- **Нельзя:** дословно копировать чужие отзывы (scraped content → демоут Google/Яндекс + ToS/копирайт) и размечать `AggregateRating` из чужих рейтингов (**прямой запрет Google → ручная санкция**). Атрибуция/ссылка юридику смягчает, SEO-проблему — нет. Свои звёзды в сниппете — только из first-party отзывов (которых нет: ~50 кликов/нед).
- **Можно (безопасно):** рейтинг+кол-во с площадки как факт с атрибуцией + **оригинальный синтез** «что отмечают покупатели» (LLM, как grounded-описания, НЕ копия) + ссылки наружу. Выгода — не звёзды, а глубина/релевантность под «{бренд} отзывы».
- Источники (для узнаваемых брендов их 5–8): otzovik, irecommend, t-j.ru, 2gis, yandex maps, be-in, tbank/reviews, WB/Ozon. Покрытие: тонко на длинном хвосте.
- Ингест: НЕ сырой trafilatura (антибот: WB режёт 429, 2gis/Я.Карты JS), а через RAG-стек (Yandex Search API/SearXNG + `LlmService` извлекает рейтинг+синтез). Прототип на 3 брендах перед пайплайном.

**🔖 БЭКЛОГ — карточки товаров** (Lyst/Musinsa-модель, закрывает «мы информационные, маркетплейсы транзакционные»):
- Инфраструктура есть (`Product`, секция «Товары бренда»), данных нет (8 товаров у 1 бренда). Узкое место — **сорсинг товарных данных** (скрейп сайтов / маркетплейс-фиды / партнёрка-affiliate), не вёрстка. Риски: свежесть цены, дубль-контент, легалка. Спрос по «{бренд} {товар}» в данных пока не виден. Решить источник до реализации.

### 02.07 — использование семантического ядра: drip-by-demand + калькулятор трафика + фильтр meta

**✅ Drip-by-demand** (`PublishTickCommand`): дрип публикует по СПРОСУ-НА-ПОКУПКУ, не RAND(). Урок: сырой
`SUM(monthly_shows)` фейково раздут у общесловных имён (Яндекс 149M, Форма, Gap, Only, Head, Prada — им
приписан весь спрос слова). Решение (идея владельца + доработка): считать спрос только по ключам, где
**имя бренда + коммерческий модификатор** (одежд/бренд/купить/магазин/сайт) → «яндекс браузер» отсекается,
«anteater купить» считается. Топ стал вменяемым: LIME/Снежная Королева/Sela/Zarina/Befree. NB: в очереди
всплывают иностранные глобалы (Zara/Nike/Ecco) — это вопрос ниша-гейта/курации каталога, не сортировки.

**✅ Калькулятор потенциала** (`app:seo:traffic-potential`, Mac): captured (реальные клики) vs потенциал при
топ-3 vs адресуемый рынок (Wordstat×CTR). Факт: **captured 17 → топ-3 196 (упущено 179, ×11.5)** — цена
позиций 9–13. Топ-возможности: yollo, yes yes brand, de4444th, cortisoljeans. Трафик = клики (не CTR);
CTR-кривая Яндекса зашита. Адресуемый (120M) — сырой верхний предел (раздут шумом ядра).

**✅ Фильтр релевантности meta-ключей** (`GenerateBrandContentCommand::filterRelevantKeywords`): оставляем
Wordstat-фразы, содержащие токен имени/слага (Cyr+Lat) → режем off-topic (mysiberia→«купить квартиру в усолье»,
breakdownbrand→«broke down», tisval→«вайлд»). Опасно для Яндекса (он читает meta_keywords). Residual:
общесловные имена всё ещё шумят (нужен distinctive-детект) — приемлемо. Применяется к будущей генерации;
existing noisy meta — при регене.

### 02.07 — ЦЕЛЬ: 1000 посетителей/день — модель и выводы

Текущее: **~4–5 кликов/день из Яндекса** (июнь 77/мес; Google даёт сравнимо: 31/нед vs 17/нед). Цель = 30k/мес
→ разрыв ×400. Показы растут быстро (166→464/день за 2 нед), клики почти нет (+15%/мес) — позиции 7–11 не конвертят.

**Модель (по семантическому ядру):** адресуемый коммерческий спрос («{бренд}+одежда/купить/…», кап 500k/бренд):
опубликованные 439 брендов = ~498k показов/мес; **очередь 4250 = ~6,49M (×13)**. Текущий capture rate =
77/498k = **0,015%**. Уравнение цели: 30k кликов/мес = весь каталог (~7M адресуемого) × capture ~0,43%,
или **~0,25% на движок** с учётом Google. Т.е. задача = «очередь в индекс + capture ×16», а не «×400 страниц».

**Выводы:** (1) масштаб один не дотянет — весь каталог при текущем capture = ~36 кликов/день; (2) только позиции
не дотянут — топ-3 по текущим запросам = ~28/день; (3) дотягивает произведение: очередь × capture 0,2–0,3%
(позиции 4–6 на вторичных «{бренд} отзывы/аналоги/купить») × два движка. При трендовых +15%/мес цель — ~3,5 года;
с разогнанным дрипом-by-demand + capture-работой — **6–9 месяцев**. Вехи: **август ~50/день** (очередь 3049
опубликована), **октябрь ~200–300/день** (когорты вызрели, рефрейм дал позиции), **Q1'27 → 1000/день**
(полный каталог + discovery + Google). Ходы: RAG-догон ~3600 неgrounded (насосу нужен контент через ~1,5 мес);
внешние сигналы/ИКС (Дзен-посты ждут ручной публикации); Метрика API (мерить «посетителей», не только клики);
через 2–3 нед поднять потолок дрипа, если Яндекс усваивает (guard позволяет).

### 02.07 — niche_status: синк Mac→прод (гейт дрипа был слеп)

Находка (после включения drip-by-demand, вопрос владельца): **на проде вся очередь была niche_status=NULL** —
классификатор гоняется на Mac, payload вердикт не нёс → гейт `<> 'off'` на проде инертен, а by-demand выводил
наверх мусор (BMW, LEGO, Dreame, «Чистая линия»). Исправлено: (1) `nicheStatus`/`nicheReason` в
BrandPayloadAssembler + markNiche в BrandIngestService (NULL не затирает вердикт); (2) разовый бэкафилл
6996 Mac-вердиктов → прод (обновлено 3603, только NULL). Итог: прод-очередь 2588 in / **397 off исключены** /
60 NULL; топ by-demand чист. NB: Nike/Crocs/Casio/Zara = 'in' корректно (ниша «мода+красота» ≠ российскость),
иностранные глобалы — отдельная курация (см. выше).

### 02.07 — ЗАПУЩЕН outreach-эксперимент (50 кандидатов, цель ≥1 платящий за 30 дней)

По плейбуку sales_offer.md §5 + правки владельца по ходу:
- **Письмо — существующий activation-шаблон** (`brand_published.*`, крючок идентичности + «что мы о вас знаем»
  + 0% комиссии + CTA «забрать страницу»), **БЕЗ цен** — двухстадийный гейт ФЗ-38 (marketing_email.md: цены
  только в warm-ответе). Cold-шаблон §4 с «5000₽» в лоб НЕ используем (конфликтовал с легальным дизайном).
- **Вычитка CSV-50:** исключены off-niche (remington, potok, kulturakovrov), иностранцы (ancientgreeksandals,
  insular, crocs, conte, cep), под сомнением отложены (bailey, slava-zaitsev — модный дом не ЦА услуги 5000₽).
- **Пре-флайт-находка:** карточки кандидатов были 404 (лиды не опубликованы), а письмо ведёт на карточку →
  батч-1 (10 шт.) опубликован push --publish ДО отправки: rax, bregeda, grunge-john, toptop, solis, balunova,
  accross, your-own-choice, bivium, yucaytes.
- `app:outreach:send --slugs=…` — точечный батч вместо авто-когорты (порядок сохраняется, гейты те же).
- Дисциплина: 10–15 писем/день (батчи 2–4 — следующие дни), метрика — ПЛАТЁЖ (5000₽ услуга продаётся в ответе).
- ⚠️ У 9 писем от 05.06 delivered_at пуст — проверить вебхук RuSender (URL /api/v1/email/webhook в ЛК RuSender).

**🔖 Отдельный трек (владелец): «услуги продвижения бренда»** — размещение в 10+ прямых каналах (каталоги
рос-брендов, витрины, соцанонсы) как самостоятельный продукт для начинающих брендов: изучить каналы →
автоматизировать подачу → продавать. НЕ обещать в cold-письме, пока не построено.

### 02.07 — outreach-эксперимент РАЗОСЛАН ПОЛНОСТЬЮ + инфраструктура ответов

**Разослано 32 письма эксперимента** (батчи: 10+8+8+6) + 9 июньских = 41 всего. Guard чужой сущности
отсёк 7 (2mood, meoney, am-one, uniize, costoso, contrabandeados, shu-777 — email-домен ≠ сайт бренда).
Воронка на момент отправки: **delivered 15+ (вебхук пишет вживую), opened 1, bounced 0**.
Часы отсчёта метрики «≥1 платящий за 30 дней» запущены.

**Инфраструктура ответов (тёплые лиды):**
- `Reply-To: nevinny@gmail.com` (env `OUTREACH_REPLY_TO`, с батча-3).
- **Форвардер hello@mail.wearbase.ru → nevinny@gmail.com** — ⚠️ СЕРВЕРНЫЙ, вне репо:
  `~/bin/forward-hello.sh` на прод-хостинге + crontab `*/10` (лог `~/logs/forward-hello.log`).
  Maildir: `/var/www/u3042786/data/email/mail.wearbase.ru/hello/.maildir/` (именно `.maildir` с точкой!).
  Пересылает обёрткой от нашего домена (SPF-safe), прочитанное уходит в cur/. При первом прогоне
  переслал 7 накопившихся писем (среди них возможны ответы на июньскую рассылку).
- Вебхук RuSender починен (формат `events[].trigger` + `payload.email`, коммит 0ac2ac8).

**Остаток ручных шагов владельца:** платёжная ссылка YooKassa под 5000₽ · налоговый статус (НПД/УСН)
· мониторить Gmail на ответы. Отложенные кандидаты: bailey, slava-zaitsev, dan4 (Киргизия).

### 03.07 — авария форвардера hello@ (петля) + разбор флоу «Забрать страницу»

**Авария (закрыта, детали в production.md «Входящая почта»):** первый форвардер (sendmail) дал
почтовую петлю — Gmail отбивал пересылки (550 unauthenticated: SPF/DKIM только у RuSender), bounce
возвращался в ящик, форвардер слал его снова → 263 письма за сутки. Фикс: форвардер переписан на
PHP + RuSender API + анти-петля (Mailer-Daemon/своё/Auto-Submitted), мусор в карантине
`~/loop-bounces-20260703/`, e2e-тест пройден. **Настоящих ответов брендов на 03.07 — ноль**;
воронка: 41 sent · 15+ delivered · 5 opened (Bybiol, Grunge John, Mura Mura, Arshenova, Urban Soul)
· **2 clicked (Bybiol — вернулся на 2-й день; BREGEDA — кликнул и отписался)** · 0 новых claim.

**Флоу CTA «Забрать управление страницей» (прослежен код+прод), 6 шагов до владения:**
1. Письмо → `/e/c/{token}` (`OutreachController::click`): rate-limit, бот-фильтр UA, `click_count++`
   → 302 на **публичную карточку** `/ru/brands/{slug}?utm_source=outreach&utm_medium=email&utm_campaign=brand_invite`.
2. На карточке — вторичная кнопка «Я владелец бренда» (hero) → `/brand-claim/{id}`.
3. `IsGranted(ROLE_USER)` → аноним: 302 `/login` → регистрация `/register` (6 полей + Turnstile;
   ⚠️ форма живёт БЕЗ локали — `/ru/register` = 404). Symfony вернёт на target после логина.
4. Claim-страница: верификация владения — email-код на доменный ящик бренда ИЛИ VK OAuth.
5. Заявка → ручная модерация в админке (approve/reject).
6. Approve → бренд в ЛК владельца.

**Точки трения (объясняют clicked=2 / claimed=0):** CTA обещает «забрать управление», а приземляет
на витрину; кнопка claim вторичная; регистрация+Turnstile+верификация+ручной approve = длинный
путь для холодного визитёра. **Бэклог-идеи (не строим без решения):** (а) баннер на карточке при
`utm_campaign=brand_invite` «Вы владелец {бренд}? Заберите страницу — 2 минуты» с прямой ссылкой
на claim; (б) click_url сразу в `/brand-claim/{id}` (интент сохранится через login target_path);
(в) событие claim_started для трекинга шагов воронки.

### 07.07 — индексация блога: слепая зона мониторинга + сломан цикл «блог→Дзен»

**Факты (прямой запрос к API Вебмастера, samples exhaustive 625 URL):** live-статей блога 29
(sitemap честный: 29 из 718 URL; ещё 13 запланированы вперёд до 13.07). В поиске Яндекса — только
3 статьи (~10%) + индекс `/ru/blog`, все из июньских батчей; июльские гиды не подтверждены.
В `yandex_query_stats` (top-500 фраз) блоговых запросов 0 — объём мал. Google: 0 блоговых строк
в `gsc_page_stats` за 26.06–04.07.

**Поломка: closed-loop «проиндексирована → публикуй Дзен» мёртв.** `checkBlogIndex()` в
`SyncGscCommand` крутится кроном на llm-сервере и читает ТАМОШНЮЮ копию `article` — стейл-снапшот
(4 строки от 11.06, без `source_file`). Реальные 42 статьи публикует `app:seo:publish-blog` на
ПРОДЕ и в llm-БД они не попадают → фильтр даёт 0 строк, ни одна живая статья ни разу не
инспектировалась, `indexed_at`/`indexed_notified_at` = 0/42. **TODO:** брать список статей с прода
(agent-API endpoint) вместо локальной таблицы, либо синкать `article` прод→llm.

**Решение (одобрено):** индекс-мониторинг НЕ фильтровать по брендам — трекать все страницы
(бренды/блог/стили/города/прочее) с `page_type` в `yandex_index_status`; отчёты с разбивкой.
Реализация — коммиты от 07.07.

**Апдейт 07.07 (вечер): сам цикл «блог→Дзен» больше не нужен как TG-уведомление-с-ручным-шагом.**
Синк статей прод→Mac закрыт (`app:blog:pull-articles`, коммит `a9f2d06`). А сама Дзен-публикация
переведена на официальный RSS-автопилот «Дзен для сайтов» — `/rss/dzen.xml`
(`DzenFeedController`) отдаёт скользящее окно последних 30 опубликованных статей, Дзен сам
поллит и публикует от привязанного канала каждые 2-5 мин. Остался один ручной шаг (разовый) —
привязать домен/фид к каналу WEARBASE в кабинете Дзена. Детали — `docs/seo_publishing_strategy.md`
§6а.

---

## 2026-07-11 — «Мой гардероб»: инвентаризация вещей (веб-ЛК + Telegram-ввод)

Фича по запросу первого реального пользователя (супруга вела каталог 38 вещей в AI-чате —
`~/Downloads/handoff_garderobe_chat.md`). Фича для всех пользователей; mobile-app (Flutter) — позже.

**Итерация 1 (веб):** сущность `WardrobeItem` (per-user нумерация `#0006` с UNIQUE(user_id,item_no),
категория-строка + datalist-подсказки, цена RUB, «любовь с первого взгляда» yes/no/unknown,
плюсы/минусы/вердикт, фото VichUploader `wardrobe_item_photo` → `public_html/images/wardrobe/`,
soft-delete `deleted_at`, `source` web|telegram|import), CRUD `/account/wardrobe`
(WardrobeController, 5 роутов) со статистикой по категориям (getStats GROUP BY), пункт в sidebar ЛК.
Импорт: `app:wardrobe:import var/import/wardrobe.json --user=EMAIL [--dry-run]`, идемпотентный
по item_no. **Ждёт экспорта её чата со всеми 38 карточками** (в handoff только сводка).
Попутный фикс: `WardrobeItem::setUpdatedAt` принимает `\DateTimeInterface` —
`EntityUserListener::preUpdate` суёт `\DateTime` в любой setUpdatedAt (иначе edit/delete → 500).

**Итерация 2 (Telegram):** диалог в @wearbase_bot — `/wardrobe` → шаблон → фото с заполненным
шаблоном в подписи (можно фото и текст раздельно; толерантный парсер `Ключ: значение` с синонимами) →
точечный дозапрос недостающего (inline-кнопки `wl:*` для «любви») → карточка + статистика + шаблон
следующей вещи. `/cancel` — сброс, черновик протухает за 24ч (lazy). Состояние —
`telegram_dialog_state` (chat_id UNIQUE, draft JSON, дедуп по last_update_id; эфемерное,
hard-delete допустим). Фото: `message.photo[] → getFile → download` (`TelegramFileFetcher`,
10 МБ/image-only) → `UploadedFile(test:true)` → Vich кладёт как веб-аплоад. Только линкованные
юзеры (telegramChatId); контакт-воронка и `unpub:` не тронуты. Логика в транспорт-агностик
`WardrobeDialogService` + чистый `WardrobeTemplate` (юнит-тесты).

**Развёрнуто:** egress-тест с прода — **api.telegram.org с regru ДОСТУПЕН** (устаревшее
«TG с прода заблокирован» поправлено в CLAUDE.md/production.md) → бот отвечает синхронно
с прода, очереди/Mac-воркер не понадобились. Деплой — по запросу.

Отложено: inline-выбор категории в боте, edit/undo из бота, эхо-фото в карточке, Flutter-app.

**Итерация 3 (семейный гардероб, веб) — в тот же день.** Продуктовое видение владельца:
семейный гардероб = key differentiator (docs/wardrobe_roadmap.md). Реализовано:
- `Family` (owner FK client) + поля на User: family_id, family_role parent|child,
  family_claim_token, claimed_at, birth_date. Семья создаётся лениво.
- **Дети без email**: реальные client-строки с синтетическим email
  `child-<familyId>-<8hex>@family.wearbase.local` + нелогинибельный пароль; «дорос» —
  claim-ссылка `/family/claim/{token}` (ставит настоящие email+пароль). Регистрация
  отклоняет служебный домен. `isManaged()`.
- **Инвайты** (`FamilyInvite`): родитель генерит одноразовую ссылку с ролью
  `/family/invite/{token}`; акцепт залогиненным юзером без семьи; повторный — 410.
- **Передача вещей**: `WardrobeTransfer` (append-only журнал: from/to/actor/note);
  item.user меняется, item_no перенумеровывается у получателя (retry на гонке),
  item.id стабилен (задел под AI-связи). Передаёт только родитель. original_owner
  фиксируется при создании (immutable) — «кто покупал» для будущих бюджетов.
- **Статусы** `wear_status`: active/reserve(на вырост)/outgrown(мала)/given_away(отдана
  из семьи, терминальный, НЕ deleted_at); given_away отфильтрован из активных выборок
  и статистики (`findGivenAwayForUser` — архив).
- **Права** — в `FamilyService` (НЕ Voter — переиспользуется TG-путём без security-токена):
  membersFor/canManage/resolveMember. WardrobeController: `?member=` селектор гардероба,
  табы членов семьи, блоки передачи/статуса/истории на show.
- UI: `/account/family` (члены, счётчики, claim/invite-ссылки), пункт «Семья» в sidebar.
- Тесты: 266/266 (FamilyControllerTest 7, WardrobeControllerTest +5, юниты).

**TG-обвязка семьи (кусок II) — СЛЕДУЮЩИЙ ШАГ**: «Кому: Маша» в шаблоне бота,
резолв члена семьи + inline-кнопки, /give. Дизайн готов, не начат.

**Итерация 4 (AI-подсказки полей) — в тот же день, на проде.** Рутина заполнения = главный
барьер ведения гардероба. Два флоу в форме new/edit:
- **По фото**: выбор файла → AJAX `/account/wardrobe/ai/photo` → OpenRouter vision
  (`WARDROBE_VISION_MODEL=google/gemini-2.5-flash-lite`, ~3.5с, ~0.05₽/фото) → категория
  (приоритет SUGGESTED_CATEGORIES)/название/цвет-сезон-фасон в notes/размер с бирки.
  Заполняются ТОЛЬКО пустые поля. Локальный gemma4 умеет vision, но риг с прода недоступен →
  прод-путь только OpenRouter.
- **По ссылке**: `/account/wardrobe/ai/url` — WB через публичный `card.wb.ru/cards/v4/detail`
  без LLM (v2 закрыт PoW-антиботом; картинка — brute-force basket-01..30, fail-soft) +
  превью фото; прочие сайты — WebScraperService + LLM-экстракция (remote, та же модель).
- Обвязка: sha1/URL-кеш 24ч, `WardrobeAiMeter` → api_usage_daily (cap `WARDROBE_AI_DAILY_CAP`
  100/день), rate-limiter `wardrobe_ai` 30/день/юзер (429), `LlmService::generateVision()`
  (multimodal, remote+local ветки). ⚠️ `OPENROUTER_API_KEY` перенесён в прод `.env.local`
  (был пуст — деплой-чеклист для LLM-фич).
- Отложено: batch-дропзона «нафоткал 10 вещей», AI в TG-боте (фото без подписи → карточка
  на подтверждение [Сохранить][Править] — дизайн готов), автоскачивание фото товара.

**Итерация 4.1 (в тот же день):** (a) фикс по первой живой вещи — телефонные фото 3-12 МБ
падали по таймауту vision-запроса → GD-даунскейл до 1024px перед отправкой (после фикса
4.7с, AI читает размер с бирки); JS-таймаут 15→25с. (b) **Учёт стоимости AI per-user** —
`ai_usage_log` (user, feature, фактическая модель, prompt/completion tokens, cost_usd от
OpenRouter через `usage.include=true` + `LlmService::getLastUsage()`); кеш-хиты/WB/local
не пишутся; read-only админка «AI-расход»; `AiUsageLogRepository::totals()`. Фундамент под
перепродажу AI-кредитов (биллинг НЕ строился). Реальные цифры: фото ≈ $0.00019, URL ≈ $0.00019.

**Итерации 4.2-4.3 (12-13.07):** (a) перезапрос AI-подсказок с карточки (endpoint принимает
item_id, путь фото через Vich resolvePath; занятые поля — через явный «Применить»); (b) ошибки
AI в журнал (ai_usage_log.status/error + monolog-канал wardrobe_ai, файловый лог на проде);
(c) **RU-прод заблокирован периметром всех AI-провайдеров** (403 анонимно: OpenRouter/Google/
OpenAI/Anthropic; workers.dev тоже) → маршрут через Cloudflare AI Gateway `wearbase`
(gateway.ai.cloudflare.com доступен с regru): env OPENROUTER_PROXY_URL/AUTH (пусто = прямой
ход с Mac), cf-aig-authorization; санитизация ошибок наружу (WardrobeAiException);
(d) **AI в TG-боте**: фото без полной подписи → «Определяю по фото…» → мерж подсказок в
черновик (ввод юзера в приоритете) → превью [Сохранить][Дополнить] (wa:-callbacks,
prefilled-шаблон), фолбэк на обычный шаблон без AI. 274 теста.

**Фикс 13.07:** «Недействительный токен» на проде при AI-подсказках — `wardrobe_ai`
это session-based CSRF (не в stateless-списке submit/authenticate/logout), а на shared-
хостинге reg.ru сессия собирается GC → запечённый в HTML токен осиротевал. JS теперь
берёт токен свежим `GET /account/wardrobe/ai/token` прямо перед запросом (fallback на
запечённый) + credentials:same-origin. Grep-памятка: длинные AJAX-страницы с session-CSRF
на reg.ru — брать токен свежим, не из HTML.

---

## 2026-07-13 — Соцсети: ценность вместо B2B-шума (Фазы 0–1)

План — `docs/social_value_plan.md` (синтез двух deep-reasoner-прогонов: рамка
аудитории + системная). Диагноз: ~43% недельной сетки было B2B-самопиаром
(calculator/manifesto/vs_marketplace) в консюмерском фиде, ни одной save-утилиты,
closed-loop разомкнут (метрики не собирались).

**Фаза 0 — замкнули петлю (`ff1c139`).** `app:social:ingest-clicks` тянет UTM-клики
из nginx-логов прода (ssh+zgrep, не Метрика — токена нет, а логи точнее: адблоки не
режут). Инкрементально (ретеншен логов ~10 дн), идемпотентно (`measuredAt` снимка =
timestamp последнего клика, не now). `CaptionGenerator::withUtm` теперь шьёт
`utm_content=p<id>`; fallback — платформа+рубрика+окно 14 дн. Крон `30 7 * * *` (Mac).

**Фаза 1 — save-утилиты (`b23d9d7`, `29ea598`, `a998faa`, `d952fda`).**
- Рефакторинг: `CaptionSourceInterface` (стратегия, `_instanceof`-автотег) — новый
  источник = один класс, `CaptionGenerator` стал фасадом.
- Сид `config/social/departed_brands.yaml` (13 записей, факты по архиву leave-russia,
  28 slug провалидированы; методика — `docs/departed_brands_seed.md`).
- Три источника: `founder_story` (пн brand_week — история из RAG-фактов, фолбэк на
  описание при недоступном Qdrant), `demand` (вт — под топ-фразу Wordstat бренда),
  `replace_departed` (пт — «чем заменить ушедших»: LLM-лид на фактах сида +
  ДЕТЕРМИНИРОВАННАЯ строка «Российские альтернативы: …» из БД, анти-галлюцинация).
- calculator/vs_marketplace выведены из недельной сетки (day=0), остались backing-def
  для `SocialPlanner::AUTO_FALLBACK`.
- Фикс попутный: off-niche бренды (Benlee=запчасти прицепов) лезли в соц-пул —
  `findFeaturedBrands` не фильтровал нишу. Добавлен гейт `niche_status != 'off'`
  (NULL проходит — бэкафилл niche-check не прогнан), 2 заражённых поста → held.

284 теста зелёные. Живой smoke подтвердил генерацию всех рубрик. НЕ запушено;
деплой не нужен (соц-конвейер живёт на Mac, прод только отдаёт логи по ssh).

**Готча (в CLAUDE.md):** `--no-debug` держит прод-контейнер по отдельному
freshness-чеку без сверки таймстампов → после правок DI отдаёт старый контейнер
(«источник не зарегистрирован») даже после `cache:clear`. Лечится
`rm -rf var/cache/dev` перед `cache:clear`.

**Дальше:** Фаза 2 (тизеры блога + дайджест каталога из SignalCollector), Фаза 3
(founder-канал: YouTube-база `topic_chunks` × brand_chunks, правило «framing, не факты»).

---

## 2026-07-15 — IG: брендированная карточка для рубрик-шаблонов вместо AI-сцены

Живой TG уже постит с картинками, но качество свободной AI-сцены (`MediaRenderer`,
Pollinations/Cloudflare Flux, без текста/лого-оверлея) для пробного постинга в IG
недостаточное. Правка аддитивная (правило «работающее не меняем»): TG/VK и остальные
рубрики не тронуты.

- Новый `App\Service\Social\CardImageRenderer` (GD, без внешних API — бесплатно/офлайн):
  тёмная карточка 1080×1080 (tailwind gray-900) + заголовок (первая строка уже
  сгенерированной подписи, word-wrap) + вотермарк «WEARBASE · #ПрямойБренд». Шрифт —
  Noto Sans (OFL, кириллица) в `config/social/fonts/NotoSans.ttf` (не в git LFS, обычный
  бинарник ~2МБ).
- Область действия — только 3 template-рубрики без привязки к бренду: `calculator`,
  `manifesto`, `vs_marketplace` (`CardImageRenderer::SUPPORTED_RUBRICS`).
- Точка врезки — `SocialGenerateCommand`: после `MediaRenderer::render()` доп. условие
  «канал=IG И рубрика поддерживается» перекрывает `$mediaPath` карточкой, если рендер
  карточки успешен; иначе остаётся прежнее поведение (AI-сцена/лого/held).
- Регистрация в `services.yaml` — новый блок в конце файла, существующие записи не
  трогали. `lint:container` зелёный, 17 тестов `tests/Service/Social` +
  `SocialIngestClicksCommandTest` зелёные (constructor DI новый, ручных
  `new SocialGenerateCommand(...)` нигде нет).
- Смоук: рендер карточки для рубрики `calculator` на реальном тексте подписи —
  читаемо, кириллица без артефактов (в отличие от AI-сцены).

**Дальше:** прогнать `app:social:plan` + `app:social:generate --limit=5` на реальных
planned-постах IG-канала (сначала `--dry-run`) и визуально проверить карточки перед
включением в живой `publish-tick`.

---

## 2026-07-18 — IG: живой постинг через Instagram API (Postiz снят с плана)

Первый живой пост — instagram.com/p/Da8VEqHDZnq/. План «поднять Postiz для IG» из
`marketing_instagram.md` **снят**: Postiz нигде не используется (ни развёрнут, ни в коде).

- Публикация — официальный **Instagram API with Instagram Login** (`graph.instagram.com`),
  без Facebook-Страницы (Meta не даёт создать FB-Страницу с РФ-аккаунта). Meta-app `wearbase`
  в Dev Mode (App Review не нужен для своего аккаунта), сценарий «Управление сообщениями и
  контентом в Instagram», права `instagram_business_content_publish` + `instagram_business_basic`.
- Новый паблишер `src/Social/Publisher/InstagramPublisher.php`: контейнерная публикация
  (`/media` → поллинг `status_code` → `/media_publish`).
- Картинки: Meta-краулер не может скачать `image_url` с РФ-прода wearbase.ru
  (error 9004/2207052) → `src/Service/Social/PublicMediaHost.php` конвертит в JPEG и rsync'ит
  на не-РФ хост (шведский VPS, systemd `igmedia`); Meta качает по голому HTTP/IP.
  Env: `IG_MEDIA_SSH_DEST`, `IG_MEDIA_PUBLIC_BASE`.
- Токен ~60 дней (`IG_ACCESS_TOKEN` в `.env.local`, живой — в `social_channel` id=5,
  egress=mac); продление — новый крон `app:social:refresh-ig-token` (`0 5 * * 1`, Mac).
- Env `POSTIZ_URL`/`POSTIZ_API_KEY` удалены из `services.yaml` — больше не используются.
- VK photo-attach остаётся на паузе (нужен VK user-токен, community-токен фото не грузит).

Подробности — `marketing_instagram.md` §4а/§5/§9, карта env — `production.md`.

---

## Бэклог

### [BUG] Системный глюк gemma «му» в генерации (заведён 2026-07-07)

gemma4:26b периодически вставляет паразитный слог «му» (и варианты «ло»/«лан») — как мусор-токен
и ВНУТРЬ слов: «ассортимумент»→ассортимент, «с окружающим миму»→миром, «спортивную одему»→одежду,
«по электронной почму»→почте, «мессмуджеры»→мессенджеры, «## му 6.»→## 6., «СДмуК»→СДЭК.
**Системный, не разовый:** в серии «чем заменить» затронуто 27 из 51 файла (гуще в dzen-копиях).
Задевает ВЕСЬ вывод gemma (обе SEO-серии + контент брендов).

Что уже сделано: детерминированный свип починил безопасные паттерны (СДЭК, заголовок «## му»,
дубль «так, так») — 6 правок. Остальное (35 флагов, точные файл:строка) — в транскрипте свипа
(агент a7a46afe, сессия 2026-07-07), т.к. восстановление слова контекстно.

План фикса (НЕ латать руками — возвращается каждую генерацию):
1. Root-cause: прогнать gemma с разными sampling-параметрами (`repeat_penalty`, temperature, seed) —
   не уходит ли глюк; если да → правка `LlmService::generateLocal`. Возможно, дефект quant'а модели.
2. Чистка накопленного: proofread-проход НЕ-gemma моделью (free-OpenRouter или разовый Claude —
   gemma сама же и вставляет, ей чистить нельзя). Восстановление тривиально по контексту.
3. Гейт: добавить в quality-gate детектор «му»-паттернов (несловарные формы) до сохранения.

⚠️ Блокирует публикацию обеих SEO-серий («чем заменить» + «уход с маркетплейсов») — до фикса дрип не гнать.

**[2026-07-19] Диагностика + частичный фикс.** Репро прямым curl в ollama `/api/chat`
(`gemma4:26b`, БЕЗ участия PHP-кода) — глюк подтверждён на уровне СЫРОГО вывода модели
(«поискат», «двику» в первом же тесте на temp=1.0/default). Слой (б) «наш парсинг»
исключён: `LlmService::generateLocal()` возвращает `message.content` как есть, без
регэкспов/склейки. `ollama show gemma4:26b --modelfile` подтвердил: это НЕ ванильная
Google Gemma, а кастомный мердж («RENDERER/PARSER gemma4», формат `gemma4`, Q4_K_M,
25.8B) — классический профиль дефекта: конкретные токены/подслоги при детокенизации
иногда рендерятся как «му»/«ло»/«лан» независимо от температуры (репро воспроизвёл
на temp 0.7–1.0). Полноценный re-quant/re-merge модели — вне рамок сессии (нужен ML-
инжиниринг на самом gguf, не код приложения).

Сделано:
1. `LlmService::DEFAULT_LOCAL_TEMPERATURE=0.7` — раньше вызовы без явной температуры
   (`generateBrandDescription`, `generateBrandAnons`, `generateBrandFaq`, `proofread`,
   `judgeArticle`, `generateAdvisorAnswer`, extract-JSON методы) шли на Modelfile-дефолте
   temp=1.0 — горячее, чем 0.7, на котором уже эмпирически стоят SEO-серии. Снижает
   поверхность, не убирает риск (глюк воспроизводится и на 0.7).
2. `ContentValidator::findGlitchCandidateWords()` — узкий, но НЕ 100%-точный кандидат-
   детектор (слог «му»/«ло»/«лан» не в начале слова — «мука»/«музыка»/«мультибренд»
   легитимны по позиции 0; «около»/«слово»/«смута»/«самому»/«приму» тоже кандидаты,
   т.к. чистой фонетикой брак не отличить от нормы — подтверждено экспериментом).
   Финальное решение — двухфакторно, за вызывающим кодом: кандидат считается браком,
   только если его САМОГО не знает Yandex Speller (уже есть в проекте, `SpellChecker`,
   до сих пор только «флаг без авто-правки» — здесь используется как чистый детектор,
   без применения suggestion). Живой прогон подтвердил точность: 17 кандидатов в
   реальной сгенерированной статье → confirmed только 3 (все — подтверждённый брак:
   «почувло», «ассортиму», «деловые»), 0 ложных срабатываний на «слово»/«около»/
   «самому»/«приму» и т.п.
3. Гейт подключён в self-heal retry (`ReplaceListicleCommand::glitchGate()`,
   `MarketplaceArticleCommand::glitchGate()` — обе явно названные в этом баге серии):
   срабатывает ТОЛЬКО когда остальной qualityGate уже чист (не тратит лишний вызов
   Speller), при срабатывании — НЕ авто-правит, а докидывает issue в fixHint и
   регенерит (как остальной self-heal), температура следующей попытки ниже.
4. Тот же гейт подключён ВТОРОЙ раз — внутри `applyProofread()` обеих команд, ПОСЛЕ
   self-heal. Живой прогон вскрыл дыру: `LlmService::proofread()` — САМ отдельный
   вызов gemma4:26b поверх уже принятого чистого черновика и может занести глюк
   заново («Бразиму»←Бразилии, «критериму»←критерию, «сортимуровка»←сортировка —
   все три реально попали в сохранённый файл ДО этого фикса). Проверка на $clean
   после proofread; при срабатывании — НЕ повторный вызов LLM (proofread — одна
   попытка, не self-heal), откат к дочищенному оригиналу без корректуры.

Живые прогоны `app:seo:replace-listicle --anchor=zara` (4 полных цикла site+dzen,
до и после виджена детектора и фикса proofread-дыры) поймали и корректно
регенерировали реальные глюки: «наиломов», «такло»/«балов», «пуховило»/«коллекму»,
плюс агримент-слип «опиралось» (жен. голос требует «опиралась» — Speller подтвердил
код=1, не ложное срабатывание). Финальный сохранённый файл (после всех фиксов)
эффективно чист.

Известный остаточный риск (НЕ устранён, честно): в одном из живых прогонов сквозь
финальный гейт всё же прошло «аудилории» (←аудитории, т→л) — Yandex Speller НЕ
флагует это слово вообще (проверено прямым curl к самому Speller, без нашего кода) —
известный, задокументированный до этой сессии предел самого Speller («пропускает
контекстные LLM-артефакты при правдоподобном окружении», см. коммит a8e05b4). Гейт
двухфакторный и полагается на словарь Speller как единственный арбитр «это реальное
слово или нет» — там, где сам словарь ошибается, гейт наследует его слепое пятно.
Цена в остальных случаях (ложное срабатывание на реальном редком слове, напр.
«самум») дешёвая — лишняя регенерация в рамках self-heal-бюджета, НЕ порча текста.

GenerateListicleCommand/SeoGuideCommand (SEO Boost, не входит в явно названные «обе
серии» этого бага) и путь генерации описаний брендов (`GenerateBrandContentCommand`,
нет self-heal retry-цикла) — тем же паттерном ещё не покрыты, механический follow-up.

# 2026-07-23 — Wardrobe MVP: новый фундамент без потери family/AI

- [x] Принято решение сохранить семейные передачи, Telegram и локальный vision AI.
- [x] Добавлены контейнер `Wardrobe` и справочник `WardrobeCategory`.
- [x] `WardrobeItem` получил совместимые nullable-связи `wardrobe` и `categoryRef`;
  прежние поля сохранены на переходный период.
- [x] Веб, batch-ingest, Telegram и JSON-импорт создают default-гардероб лениво.
- [x] Идемпотентная миграция создаёт 10 верхних категорий и бэкфиллит существующие вещи.
- [x] Второй срез: 30 подкатегорий, quick/full формы и вычисляемый completion status.
- [x] Третий срез: мультифото-галерея, выбор cover, soft-delete фото, архив/restore.
- [x] Четвёртый срез: поиск по тексту/номеру и фильтры категории, бренда, цвета,
  размера, сезона и заполненности с семейной изоляцией.
- [x] Пятый срез: статистика стоимости, заполненности, любимых вещей, категорий,
  сезонов, брендов, цветов и статусов с семейным сравнением.
- [ ] Затем: JSON/CSV export.
- [ ] Добавить DB-инвариант «один активный default-гардероб на владельца» после
  проверки совместимости с будущими типами family/child/seasonal.

---

## 2026-07-31…08-01 — IG-спринт: карусели+Reels, сценарии v4, плейбук P0, эксперименты E1×E4

Директива фокуса сменена ([[focus-directive-ig-audience]]): «первая продажа» отменена,
цель — рост аудитории Instagram. Полный контекст: `marketing_instagram.md` §4б–4в,
`reels_viral_playbook.md` (программа A/B + бэклог §9), разборы — `docs/reels_references/`.

**✅ Сделано (всё в PR #79 → ветка feat/ig-brand-galleries-reels-ab):**
- [x] [M] **Карусель в InstagramPublisher** (children/CAROUSEL, ≤10 слайдов; media_path = список путей построчно) + Reels (REELS/video_url/share_to_feed/cover_url/audio_name). TG/VK берут первый слайд.
- [x] [M] **Галереи брендов**: `app:social:enqueue-brand-gallery` (рубрики brand_gallery/brand_reels, дедуп по бренду, 288 брендов в очереди), `GallerySlideRenderer` (нормализация 4:5, счётчик, прогресс), `ReelsSlideshowRenderer` (ffmpeg-слайдшоу 9:16).
- [x] [S] **Vision-классификация кадров** (`app:social:classify-frames`, gemma4:26b, 1758 фото размечено: person/flat/scene/other) + ранжированный порядок галерей (товарный вертикальный кадр первым). ⚠️ класс scene почти не используется моделью (1/1758) — при случае упростить до трёх.
- [x] [M] **Сценарии текста на слайдах v4 «факт вперёд»** (v1 «ярлыки» и v2/v3 забракованы владельцем): f1.rag (хук = лучший заземлённый LLM-факт, «Чей — в конце.») / f2.fee («Маркетплейс: до 67%.» / «У этого бренда — 0%.») / H1 departed. Жёсткий гейт LLM-фактов (пиксельный бюджет imagettfbbox, заземление цифр и 5-префиксов, латиница-аббревиатуры, анти-слоган, производственные претензии). `script_key`/`script_json`/`slide_count` в social_post; сценарий один на бренд (карусель+рилс не разъезжаются).
- [x] [S] **Подпись**: первая строка = hookA + «Дальше — в ролике» (не спойлерит развязку, имени бренда нет в первых 125 знаках); «ссылка в профиле» перенесена ИЗ-ПОД хэштегов перед них.
- [x] [M] **Метрик-коллектор `app:social:collect-metrics`** (insights доступны текущим токеном Instagram Login, скоуп manage_insights НЕ нужен — его в этой схеме не существует): reach/views/saves/shares/avg_watch_ms append-only с переносом link_taps. evaluate: разрез (рубрика × variant × script_key), watch_ratio по duration_ms.
- [x] [S] **Аудио**: 3 трека Mixkit (лицензия free-commercial, нормализованы −16 LUFS), трек по id поста, audio_name «WEARBASE · Прямой бренд»; P0: fade-in 0.12с, volume-ramp +4 дБ на развязке вместо fade-out (луп-стык не слышен).
- [x] [M] **Фиксы рендера по браковке владельца**: дрожь зума (апскейл ×3 перед zoompan), статичный UI-слой (счётчик/прогресс/плашки — PNG-оверлей ПОСЛЕ zoompan), обрыв клипа на 4.59с (GD q≥90 пишет 4:4:4, q88 — 4:2:0 → concat-демуксер ломал zoompan; пересборка на per-input filter_complex).
- [x] [S] **P0 плейбука (§9 №1–6)**: пер-слайдовые длительности (flat_150/hook_hold), duration_ms, аудио-рамп, валидация «hookA обязателен, вопрос ⇒ ответ в развязке», кап 38с (шов трека на 40.000с).
- [x] [S] **A/B**: logo_first/logo_last закрыт решением владельца (logo_last зафиксирован); **E1×E4 запущены факториалом 2×2** (E1 flat_150/hook_hold → watch_ratio; E4 tags_3/tags_0 → views; variant кодирует пару, только brand_reels). Правило остановки: ≥20 постов/ветка И ≥14 дней И дельта ≥20%.
- [x] Опубликованы живьём: карусель DbbwKWHDXqZ, рилсы DbbwNksAsof / Dbc6LJjkgzS (v2), DbdR_7xCnrP (v3), **DbfkwskGJyF (v4+P0, hook_hold)**.
- [x] Агенты в .claude/agents: reels-maker, smm-marketer (+ владельцем: reels-trend-scout, viral-reels-analyst).

**⏳ Осталось (соц-блок, в порядке приоритета):**
- [ ] [решение владельца] **Held-гейт f1.rag**: grounded-посты с LLM-фактами копятся в held (OLLYTECH, HRDCR, yuzhka, ZIPATCH, ART IN CLO…) — одобрить первую партию в админке; если ок — снять гейт совсем, иначе ветка f1.rag не набирается в E1.
- [ ] [решение владельца] `rate_start` IG 3→5 в админке — под темп 2 бренда/день (карусель+рилс) + обычная сетка.
- [ ] [M] **P1 плейбука** (§9 №7–18): луп-замыкание (E5), кегль 54→76 (E2), режимы покрытия текстом (E3/E3b), timeline-оверлеи → шаблон ticker, one_photo (zoompan 25–40%, бренды с 1 фото), chapters (MAX_BITS→динамический), комментарий-гейт «список в первом комментарии» (E7), нумерация брендов.
- [ ] [S] **P2 плейбука** (§9 №19–30): polished/raw чередование, blur-fill вместо белых полей (E8), строб-вход, леттербокс, атрибуция «Фото: сайт бренда», больше треков (+тег build, снимает кап 38с).
- [ ] [S] Крон `app:social:collect-metrics` в scheduled_command (2×/день, host=mac) — сейчас снимается вручную.
- [ ] [S] OCR-гейт кадров с чужим CTA («айтемы в тг-боте» вшитые в фото брендов) — из ревью маркетолога.
- [ ] [M] Разведка ниши по процедуре плейбука: reels-trend-scout (нужен подключённый Chrome MCP) → viral-reels-analyst — обновление датасета референсов и сверка наших рилсов.
- [ ] [track] TTS поверх слайдшоу (§10.1) — открыл бы форматы 40–90с; без него потолок 38с. Не решение, трек.
- [ ] [S] VK photo-attach — по-прежнему на паузе (нужен user-токен).
- ⚠️ Грабли выкатки: слайды/оверлеи кешируются по имени файла — правка рендера/копии требует удаления производных (`public_html/images/social/{gallery,reels}/p{id}-*`) И сброса постов в planned, иначе крон generate (*/30) переиспользует старое; крон на время массовых правок ВЫКЛЮЧАТЬ (scheduled_command id=15).

---

## Автономное самообслуживание каталога — 2026-08-03 (работа ночи 29→30.07)

> Полный дизайн — [brand_self_service.md](brand_self_service.md), верификация владения —
> [brand_verification_options.md](brand_verification_options.md). Здесь только состояние и остаток.
> Повод: самрегистрация бренда «Русский бренд АХ!» (prod id 3673) выявила, что ручная модерация как
> процесс не работает — предыдущий самрег «all4b2b» висел неразобранным 4 суток.

### Сделано и задеплоено (PR #69–#77, все с зелёным CI)

- [x] #69 MVP премодерации: сущность/таблица `brand_moderation`, хук самрегистрации,
  `GET /api/v1/moderation/queue` + `POST /api/v1/moderation/verdict`, `ApplicationMatcher`
  (детерминированный, без LLM; `identity_match` отдельно от `control_proof`), команда
  `app:brand:moderate-tick`, TG-действия approve/request-changes/reject.
- [x] #70 CRUD ссылок в ЛК бренда (`/brand/links`) — раньше владелец **физически не мог** дать
  ссылку на свой сайт. Soft-delete, `Assert\Url` http/https, `ChoiceType` вместо свободной строки,
  лимит 8, запрет дублей, owner-provenance, проверка владения до CSRF.
- [x] #71 **P0-безопасность**: валидация загрузок (логотип, аватар, фото гардероба, фото товара) —
  только jpeg/png/webp, 5 МБ, 5000px. Прод отдаёт `.svg` как `image/svg+xml` без `nosniff` и без CSP
  → SVG со скриптом = stored XSS на origin → сессия админа EasyAdmin. Валидация обязана стоять
  **на свойстве сущности**: `VichImageType` — корневой тип, опцию `constraints` не проксирует.
  Попутно: фото товара вообще не загружались (500 из-за незаполненного `ProductImage::slug`).
- [x] #72 `app:brand:moderation-backfill` — идемпотентно заводит строки очереди историческим самрегам.
- [x] #73 `no_trace` больше **не даёт `reject`** (инвариант закреплён тестом); `reject` только при
  `niche='off'` / `origin IN ('foreign','unknown')`.
- [x] #74 Зонд доменов-кандидатов из локальной части email заявки (`ah.silk@yandex.ru` → `ahsilk.ru`)
  + мультидвижковый контактный поиск. Кандидат принимается только при подтверждении матчером —
  это отсекает `ahsilk.com` (другая, китайская компания).
- [x] #75 Фикс 500 на записи вердикта: `?\DateTimeInterface` под `#[ORM\Column]` **без явного
  `type:`** → Doctrine не выводит тип по интерфейсу, колонка деградирует в string,
  `flush()` падает с «Object of class DateTime could not be converted to string». Тот же латентный
  дефект был во втором поле и уронил бы TG-кнопку админа. Свип по всем сущностям: других нет.
- [x] #76 Подтверждённый сайт → `brand_link`; классификатор хостов вынесен в
  `Service/Support/LinkTypeClassifier` (дублировал `OutboundClickController::classify()`).
- [x] #77 Явный `?id=` в очереди игнорирует статус — ручной перепрогон после правок владельца.
- [x] Подпись TG-действий переведена с `kernel.secret` на `AGENT_API_SECRET`: `APP_SECRET` на Mac
  **пуст** при непустом на проде, кнопки молча не работали бы. Пустая соль = fail-closed.
- [x] `.gitignore`: добавлен `/public_html/images/products` — фото товаров могли уехать в публичный
  репозиторий (тот же класс, что фикс гардероба в 3f07b08).
- [x] `PAYMENT_SECRET_KEY` прописан на проде (его не было вовсе → форма платёжных реквизитов
  отказывала с «обратитесь в поддержку»). Формат: base64 от 32 байт. Проверено: зашифрованных
  данных не существовало (риск потери = 0), `.env.local` исключён из rsync (`ci.yml:105`).

### Состояние брендов на проде

| id | бренд | вердикт | что дальше |
|---|---|---|---|
| 3673 | Русский бренд АХ! | `identity=confirmed`, `control=unconfirmed`, `request_changes` | конвейер сам добавил 3 ссылки (ahsilk.ru, instagram, vk); НЕ опубликован — логотип-скриншот, товар без цены, нет ИНН/года/места производства |
| 3672 | Новый бренд all4b2b | `no_trace`, `request_changes`, флаг `empty_card` | пустая карточка, нужен владелец |

### Остаток работ

- [ ] **Этап 2 автономии** (главное): `OnboardingSignal::touch()` + Doctrine-листенер на
  Brand/BrandLink/BrandImage/Product/BrandStore (триггер = действие клиента, взводить только когда
  в токене `App\Entity\User` из `BrandUser` этого бренда); поля `recheck_requested_at`/`settle_after`/
  `state_hash`/`nudge_count`/`timeout_at` в `brand_moderation` (дебаунс 10 мин, чтобы 10 правок
  подряд давали один прогон); `ModerationPolicy::decide()` по таблице решений §3; лестница таймаутов
  с письмами (3д/7д напоминание → 14д архив — иначе пустышки висят вечно); строго `niche='in'`/
  `origin='ru'` **в политике**, не в запросе дрипа; инварианты в `app:rag:doctor` + положительный
  heartbeat в дайджест («простой ≠ баг»).
- [ ] **Блокер провенанса перед зеркалированием на Mac**: `BrandIngestService.php:60-70` применяет
  `city/country/foundingYear/description/anons` безусловно, а `BrandProfileController` не штампует
  `PROV_OWNER` (эталон — `BrandStoreController::markOwnerProvenance()`). Пока самрегов нет на Mac —
  латентно; в момент зеркалирования RAG затрёт тексты живых брендов. Чинить ДО зеркалирования.
- [ ] **SVG-логотипы из обогащения**: валидация ЛК закрыта, но `app:brand:enrich-logo` тащит SVG с
  произвольных сайтов в webroot (сейчас там 2233 файла; на 30.07 проверены — чисты). Варианты:
  `enshrined/svg-sanitize` при скачивании, либо отказ от SVG в этом канале, либо cookieless-origin
  для UGC. Своя фильтрация регулярками — плохая идея. Плюс запросить у reg.ru глобальный
  `nosniff` + CSP (конфиг nginx нам недоступен). Нужна и команда-ревизор существующих файлов
  (без ложных срабатываний на Adobe-boilerplate `<foreignObject>` и base64-превью).
- [ ] **Круговой email-код + авто-грант**: `BrandClaimService::startEmailCode()` шлёт код на
  `brand.email` из нашей БД — у самрега этот адрес ввёл сам заявитель, доказательство контроля = 0.
  Поверх этого на проде `BRAND_CLAIM_AUTOGRANT_EMAIL=1` → мгновенное владение без ревью, т.е. дешёвый
  перехват 3.6k бесхозных карточек. Решение: не выключать авто-грант, а различать по провенансу
  адреса (`owner` → ручная ветка) + гард по истории исходящих кликов.
- [ ] **Перевыпустить ключ Yandex Search API** (нужен владелец, Yandex Cloud): текущий отдаёт
  `401 Unknown api key`, `BRAVE_SEARCH_API_KEY` пуст → из трёх бэкендов `BrandSourceFinder` живой
  только SearXNG, и он бесполезен для точных запросов (по телефону возвращает gemini.com). Discover
  деградировал давно и не только у модерации.
- [ ] **Письмо владельцу «АХ!»**: логотип вместо скриншота, цена товара (на её сайте 20 000 ₽, цена
  живёт в вариантах), ИНН/ОГРНИП, год основания, место производства. ⚠️ Секретный ключ YooKassa
  **не запрашивать** — см. решение по OAuth ниже.
- [ ] **Решение по публикации 3673** — кнопка approve в TG рабочая; моё мнение: после логотипа и цены.

### Решение 2026-08-03: уходить от хранения секретов платёжек на OAuth

Владелец: «риски компрометации сервера и репутационные потери с финансовыми последствиями мне не
нужны». Момент идеальный — сохранённых секретов **ноль** (`secret_encrypted` пуст у единственного
счёта), миграции не потребуется; главное — не начать их накапливать.

Честная граница: OAuth **не** устраняет компрометацию сервера (токен — такой же bearer-секрет на
нашем диске). Он даёт узкий scope (приём платежей без выплат/возвратов/настроек), отзыв мерчантом в
один клик без перегенерации ключа, и аудит в его кабинете. Чтобы требование выполнялось по существу,
нужны ещё: узкий scope со стороны провайдера **и** ключ шифрования вне этого сервера (KMS).

- [ ] **Разведка (блокирует всё остальное)**: есть ли партнёрский OAuth у `yookassa` (единственный
  активный), `tinkoff`, `cloudpayments`, `sbp`, `sber`, `robokassa`, `payselection`, `paykeeper`;
  **кому его дают** (если только крупным по договору с ИТ-аудитом — для нас его де-факто нет); какие
  scopes; есть ли **API-онбординг мерчанта** (создание магазина по API — для автономного каталога
  ценнее OAuth, т.к. сегодня регистрация в ЮKassa это самый длинный шаг воронки); насколько правдив
  флаг `supports_marketplace` в `payment_provider`. Строить флоу против API, к которому не допустят,
  нельзя.
- [ ] После разведки: модель данных OAuth-подключений (приложение платформы + токен на бренд, scope,
  срок жизни, отзыв), connect/callback, refresh/revoke, вывод из эксплуатации формы ввода секрета.

### Чем прервались

Четыре делегированных агента (разведка OAuth, провенанс, SVG, claim-перехват) упали одновременно:
**исчерпан месячный лимит расходов организации**. Ни одного коммита не сделано, осиротевший worktree
и две пустые ветки вычищены, `main` чист на #77. Возобновлять после поднятия лимита.

### Вывод, который стоит унести

Четыре дефекта из семи (`no_trace → reject`, 500 на вердикте, незамкнутая передача ссылок,
невозможность перепрогона) **не поймали ни тесты, ни ревью** — только прогон по живым данным. Тесты
были зелёные на каждом шаге, а конвейер при этом выносил настоящему бренду `reject`. Локальная модель
в ревью дала 🔴 четыре раза и попала мимо все четыре (тип FK, md5-слаг, слова «token/secret»,
SQL-инъекция там, где стоит `(int)`); зато один её 🟡 был прав — дублирование классификатора.
**Правило: ни один шаг автономного контура не включаем в крон, пока он не отработал вручную на
реальных данных.** Иначе автономия начнёт молча отказывать живым брендам.

---

## 2026-08-03 — регулярный ингест TG-каналов + CI/CD (GitHub Actions)

### Сделано

**1. Регулярный инкремент TG-каналов в RAG советника** (память [[drmax-seo-knowledge-source]]).
- Карта per-channel (role+name) вынесена из кода в `config/knowledge/channels.yaml`, читается
  `App\Service\Knowledge\KnowledgeChannelRegistry`; общий эмбеддинг-код — в `KnowledgeIngestor`
  (убраны дублирующие const из `IngestKnowledgeChannelsCommand` и `AdvisorRag`).
- `TgChannelScraper` (скрап `t.me/s/<handle>` с Mac напрямую, без прокси) + команда
  `app:kb:sync-tg --channel=<handle>` — пишет .txt только для новых постов и до-эмбеддит только их.
- Крон `scheduled_command` env=dev: `app:kb:sync-tg --channel=drmaxseo`, `30 8 * * *` (до snapshot 08:50).
- **Добавить новый канал = 2 шага:** строка в `channels.yaml` + крон-строка. PHP не трогать.
- Ограничение: `t.me/s/` отдаёт только последнюю страницу (~20 постов) → первичный backfill истории
  канала — по-прежнему разовой ручной цепочкой `?before=<msgid>`.

**2. CI/CD** (полная схема и грабли — память [[wearbase-cicd-github-actions]]).
- `main` под branch protection: мерж только через PR (0 апрувов, admin-bypass вкл.), force-push/удаление off.
- Правило branch-per-change добавлено в `AGENTS.md` (Rule 5) — Codex читает по умолчанию.
- Workflow `.github/workflows/ci.yml`: PHPUnit на каждом PR (307 тестов, PHP 8.4, sqlite) →
  на мерже в main автодеплой на прод (rsync из облака + миграции + cache:clear + smoke), деплой `needs: tests`.
- Секреты `DEPLOY_*` в GitHub Secrets, выделенный ed25519-ключ (основной не засвечен).
- Ручной `/deploy` (rsync с Mac) остаётся независимым запасным путём.

### Осталось / follow-ups

- [ ] **Playwright E2E в CI** — сознательно отложено (сейчас только PHPUnit; E2E требует живой сервер
  в раннере, флакает). Добавить, когда CI устоится.
- [ ] **Дисциплина `composer.lock`**: в этой сессии lock висел на admin-core 1.0.4 (прод уже на 1.0.6) →
  CI-контейнер не компилился. Всегда коммитить lock после `composer update`, иначе CI (ставит по локу)
  разъезжается с прод-vendor.
- [ ] Мерж в main деплоит на прод **на любой PR, включая докс-only** — при желании можно сузить триггер
  деплоя по путям (paths-ignore), пока не трогал.
- [ ] Роль `seo` оставлена в `AdvisorRag::IDEA_ROLES` — SEO-знания (DrMax и др.) подмешиваются в
  автономную генерацию идей советника (раньше в памяти было обратное — устарело).
