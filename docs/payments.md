# Платежи, провайдеры и юридический слой

> Подсистема приёма оплаты заказов, мультишлюзовая маршрутизация, юр.лица продавцов,
> версионирование оферт и регламент возврата предоплаты (ЗоЗПП).
> Backlog подключения новых провайдеров — в [tasktracker.md](tasktracker.md) (раздел «Платёжные провайдеры»).

## Принципы

1. **Деньги за заказ уходят напрямую бренду** — оплата проходит на реквизиты юр.лица бренда у выбранной платёжки, **не** через расчётный счёт площадки.
2. **Площадка не берёт комиссию с продаж** — монетизация только через подписку (тарифы). Платежи за подписку идут на **платформенные** реквизиты YooKassa.
3. **Юридически площадка — «владелец агрегатора»** (ЗоЗПП), с 01.10.2026 — оператор посреднической платформы (289-ФЗ). Подробнее — раздел «Юридический слой».
4. Поскольку деньги не оседают на юрлице площадки — **лицензия платёжного агента не нужна** (БПА — банк/шлюз).

---

## Модель данных

| Сущность | Таблица | Назначение |
|---|---|---|
| `SellerLegalEntity` | `seller_legal_entity` | Юр.лицо продавца. 1 бренд → N юр.лиц, с периодами действия (`effective_from`/`effective_to`), `status` active/archived. Меняются/устаревают во времени. |
| `PaymentProvider` | `payment_provider` | Справочник платёжек (каталог). `code`, `is_active`, `supports_direct`/`supports_marketplace`. |
| `SellerPaymentAccount` | `seller_payment_account` | Счёт приёма: юр.лицо ↔ платёжка + реквизиты (`account_ref`, `secret_encrypted`, `config` JSON), `mode` (direct/marketplace), `is_primary`, `status`. Уникум `(legal_entity_id, provider_id)`. |
| `OfferDocument` | `offer_document` | Иммутабельная версия оферты/политики. `type`/`locale`/`version` уникальны, `content_hash` (sha256), `effective_from`, `requires_reacceptance`, `status` draft/published/archived. |
| `OfferAcceptance` | `offer_acceptance` | Append-only факт акцепта: кто/когда/версия/IP/UA/`context_type`. |

**Снимки на `Order`** (фиксируются при создании платежа — продавец-of-record и куда ушли деньги):
`seller_legal_entity_id`, `seller_payment_account_id`, `accepted_offer_id`, плюс регламент возврата:
`prepayment_refund_requested_at`, `seller_delivery_confirmed_at`, `refund_confirmation_sent_at`.

**Снимок на `Subscription`**: `accepted_offer_id` (редакция оферты продавца, действовавшая для подписки).

> FK-замечание (см. CLAUDE.md): новые PK — `INT UNSIGNED`; FK-колонки подобраны под тип цели
> (`brand_id INT` → `brand.id`, `user_id INT` → `client.id`).

### Миграции (ветка task-20, 2026-06-03)
- `Version20260603_legal_offers` — `seller_legal_entity`, `payment_provider` (+seed yookassa/tinkoff/cloudpayments/sbp), `seller_payment_account`, `offer_document`, `offer_acceptance`, ALTER `order`/`subscription`.
- `Version20260603_seller_offer_seed` — публикуемая `seller_offer` v1.0.0 (плейсхолдер, утверждает юрист).
- `Version20260603_sber_robokassa_providers` — каталог: Сбер, Robokassa (`is_active=0`).
- `Version20260603_payselection_paykeeper_providers` — каталог: Payselection, PayKeeper (`is_active=0`).

---

## Абстракция платёжных шлюзов

`src/Payment/Gateway/`:

- **`PaymentGatewayInterface`** — `code()`, `createRedirectPayment(...)`, `fetchStatus(...)`.
- **DTO**: `PaymentInitResult` (gatewayPaymentId + confirmationUrl), `PaymentStatusResult` (статус нормализован к словарю YooKassa: `succeeded|canceled|pending|failed` + сумма + валюта).
- **`PaymentGatewayRegistry`** — резолв по коду провайдера; собирает реализации через тег `app.payment_gateway` (см. `_instanceof` в `config/services.yaml`). Неизвестный код → исключение.

### Реализации (7 шлюзов)
| Код | Класс | Статус | API / реквизиты |
|---|---|---|---|
| `yookassa` | `YooKassaGateway` | **live** (эталон) | SDK `yoomoney/yookassa-sdk-php`. account_ref=shopId, secret=ключ |
| `tinkoff` | `TinkoffGateway` | `[~]` sandbox-unverified | Merchant API v2 (Init/GetState, токен sha256). account_ref=TerminalKey, secret=Password |
| `cloudpayments` | `CloudPaymentsGateway` | `[~]` | REST orders/create + payments/find, Basic auth. account_ref=Public ID, secret=API Secret |
| `sber` | `SberGateway` | `[~]` | REST register.do/getOrderStatusExtended.do (form, копейки, валюта 643). account_ref=userName, secret=password |
| `robokassa` | `RobokassaGateway` | `[~]` | Подписанный redirect (md5) + OpStateExt. account_ref=MerchantLogin, secret=**JSON `{"p1","p2"}`** (два пароля) |
| `payselection` | `PayselectionGateway` | `[~]` (сверить подпись) | REST hosted, HMAC-SHA256. account_ref=SiteId, secret=SecretKey |
| `paykeeper` | `PaykeeperGateway` | `[~]` | JSON API на поддомене (token+Basic). account_ref=login, secret=password, `config.base_url`=поддомен |

> `[~]` = адаптер написан по документации, **не проверен в песочнице**, провайдер `is_active=0`.
> Tinkoff/CloudPayments/Sber/Robokassa/Payselection/PayKeeper **в проде не вызываются** (см. «Гарантия»).

### Вспомогательные сервисы
- **`SecretCipher`** (`src/Service/`) — libsodium secretbox; ключ `PAYMENT_SECRET_KEY` (base64 32 байта). Случайный nonce, формат `base64(nonce‖cipher)`. Пустой/неверный ключ → громкая ошибка (секрет в открытом виде не сохраняется).
- **`YooKassaClientFactory`** — строит `YooKassa\Client` из произвольных кредов (платформенные для подписок, кредов счёта для заказов).

---

## Потоки оплаты

### Заказ (деньги бренду)
`CheckoutController` → `PaymentService::createOrderPayment($orders, $returnUrl)`:
1. Все заказы одного чекаута — одного бренда (иначе отказ; один шлюз на платёж).
2. `SellerLegalEntityRepository::findActiveForBrand($brand)` → `getReadyPrimaryAccount()`.
3. Если готового счёта нет → `null` + предупреждение (чекаут показывает «оплата не настроена»).
4. `PaymentGatewayRegistry::get(provider.code)->createRedirectPayment(...)` → редирект-URL.
5. На заказы пишется снимок: `sellerLegalEntity` + `sellerPaymentAccount` + `gatewayPaymentId`.

### Подписка (доход площадки)
`PaymentController::subscribe` → `PaymentService::createSubscriptionPayment(...)` — **платформенный** YooKassa-клиент (`platformClient()`, env `YOOKASSA_SHOP_ID`/`YOOKASSA_SECRET_KEY`).

### Вебхук
`POST /payment/yookassa/webhook` → `PaymentService::handleNotification()`:
- IP-allowlist YooKassa; парсинг `NotificationFactory`.
- **subscription** → авторитетный статус через `platformClient()->getPaymentInfo()`.
- **order** → статус через `gateways->get(account.provider.code)->fetchStatus($account, $id)` (клиент того счёта, на который шёл платёж; для legacy без снимка — платформенный).
- refund — без обращения к API.

> Вебхук пока **только YooKassa**. Для остальных провайдеров нужны отдельные роуты с парсингом и **верификацией подписи** — отдельная задача на активацию.

### Гарантия «не примет деньги до проверки»
`SellerPaymentAccount::isReadyToAcceptOnline()` пускает **только `yookassa`** (active + provider yookassa + непустые `account_ref`/`secret_encrypted`). `getReadyPrimaryAccount()` и баннер готовности используют тот же предикат. Активация другого провайдера требует: sandbox-прогон → `is_active=1` → расширить `isReadyToAcceptOnline()` на его код → webhook-роут.

---

## ЛК бренда

| Раздел | Роут | Что делает |
|---|---|---|
| Дашборд | `/brand/dashboard` | Баннер готовности оплаты (тот же предикат, что чекаут), метрики (выручка/заказы/опубликовано), статус подписки/триала |
| Реквизиты и оплата | `/brand/payments` | Юр.лица + счета; **пикер «Подключить {платёжку}»** (активные провайдеры) → форма счёта с преселектом (`?provider=code`) и подсказками по реквизитам (`PROVIDER_HINTS`) |
| Оферта | `/brand/offer` | Показ + акцепт оферты продавца (**только владелец**, BrandUser ROLE_OWNER); запись `OfferAcceptance` (IP/UA). Баннер+бейдж в layout через `BrandLkExtension::sellerOfferPending()` |
| Заказы | `/brand/orders/{id}` | Снимок продавца/счёта; «покупатель получил товар»; трекер возврата предоплаты (10 дней); внутренняя заметка |

Формы: `SellerLegalEntityFormType`, `SellerPaymentAccountFormType` (поле `secret` unmapped — шифруется в контроллере, не отдаётся назад; форс единственного `is_primary`).

Лендинг брендов `/{_locale}/for-brands` — блок «Платформа приёма платежей»: выплаты напрямую, 0% комиссии, **динамический список активных платёжек** (`LandingController::forBrands`).

---

## Юридический слой

### «Владелец агрегатора» (ЗоЗПП) — возврат предоплаты, правило 10 дней
Покупатель вправе требовать возврат предоплаты **у площадки**, даже если деньги ушли бренду.
Площадка освобождается, если в течение **10 дней** направит покупателю подтверждение от продавца.
Модель на `Order`:
- `prepayment_refund_requested_at` — дата поступившего требования (вводится явно в ЛК, бренд не «запускает свой таймер»);
- `getRefundConfirmationDeadline()` = +10 дней; `isRefundConfirmationOverdue($now)`;
- `seller_delivery_confirmed_at` — «покупатель получил товар» (отдельный сигнал, т.к. статус-машина не идёт дальше «отправлен»);
- `refund_confirmation_sent_at` — копия подтверждения направлена покупателю (гасит требование).

### Версионирование оферт
Опубликованную `OfferDocument` не редактируем — новая редакция = новая запись (новый `version`/`content_hash`/`effective_from`). Акцепт фиксируется per-версия в `OfferAcceptance` (append-only). Текущая редакция — `OfferDocumentRepository::findCurrentPublished(type, locale)`; факт акцепта — `OfferAcceptanceRepository::hasAccepted(user, doc)`. Покупательская оферта акцептуется один раз при регистрации; `requires_reacceptance` помечает редакции, требующие повторного акцепта.

> ⚠️ Тексты оферт — плейсхолдеры, **итоговые редакции утверждает юрист** (особенно под 289-ФЗ).

---

## Как подключить нового провайдера (чек-лист)

1. **Адаптер** `src/Payment/Gateway/{Name}Gateway.php implements PaymentGatewayInterface` — `createRedirectPayment` + `fetchStatus` + нормализация статуса. Автотегируется (`_instanceof`).
2. **Код провайдера** — константа в `PaymentProvider` + строка в каталоге (миграция, `INSERT IGNORE`, `is_active=0`).
3. **Подсказки реквизитов** — добавить в `BrandPaymentController::PROVIDER_HINTS`.
4. **Sandbox-прогон** с тестовыми кредами (base_url/test-флаг через `SellerPaymentAccount::config`).
5. **Webhook-роут** провайдера: парсинг + **верификация подписи**, затем общий апдейт статуса.
6. **Активация**: `is_active=1` + добавить код в `SellerPaymentAccount::isReadyToAcceptOnline()`.
7. **54-ФЗ**: определить, кто пробивает чек (продавец / шлюз за продавца).

---

## Конфигурация (env)

| Переменная | Назначение |
|---|---|
| `YOOKASSA_SHOP_ID` / `YOOKASSA_SECRET_KEY` | Платформенные реквизиты (подписки) |
| `PAYMENT_SECRET_KEY` | base64 от 32 байт — ключ шифрования секретов счетов (`SecretCipher`). Генерация: `php -r "echo base64_encode(random_bytes(32));"`. **Обязательно задать в проде.** |

---

## Тесты и статус проверки

Зелёные тесты (no-DB / транзакционные SQLite):
`SecretCipherTest`, `SellerLegalEntityTest`, `SellerLegalEntityRepositoryTest`, `OrderRefundTest`,
`OrderRepositoryRevenueTest`, `OfferAcceptanceFlowTest`, `ProductImageRelationTest`,
`PaymentGatewayRegistryTest`, `RobokassaGatewayTest`.

Харнес `WebTestCase` **починен** (2026-07-07): тест-БД = одноразовый SQLite `var/test.db`, схема
провижинится из сущностей в `tests/bootstrap.php`, `UserFactory` персистит реальных пользователей
(логин через тот же провайдер, что в проде). Архитектура — [docs/testing.md](testing.md).
Функциональные тесты ЛК бренда / аккаунта / корзины снова зелёные (`loginAsBrandOwnerWithBrand` и т.п.).

**Ещё не покрыто автотестами**: формы (импорт/переводы/оферта/счёт), реальный вебхук-путь заказа
(следующий шаг — тесты ревенью-путей поверх починенного харнеса). Все live-money-пути пока требуют
ручной проверки перед продом.

### Известные ограничения
- 6 провайдеров — construction-only, в проде недостижимы (см. «Гарантия»).
- Payselection: схему подписи/поля ответа сверить с актуальной докой+SDK.
- Robokassa: `InvId = crc32(idempotenceKey)` — для прода привязать к числовому id заказа.
- `sellerOfferPending()` и `getActiveBrand()` резолвят бренд через нескопированный `findOneBy(['user'=>$user])` (multi-brand неоднозначность — переключатель брендов отдельной задачей).

---

## Карта файлов

```
src/Entity/            SellerLegalEntity, PaymentProvider, SellerPaymentAccount, OfferDocument, OfferAcceptance
src/Repository/        SellerLegalEntityRepository, PaymentProviderRepository, SellerPaymentAccountRepository,
                       OfferDocumentRepository, OfferAcceptanceRepository
src/Payment/Gateway/   PaymentGatewayInterface, PaymentGatewayRegistry, PaymentInitResult, PaymentStatusResult,
                       YooKassaGateway, TinkoffGateway, CloudPaymentsGateway, SberGateway, RobokassaGateway,
                       PayselectionGateway, PaykeeperGateway
src/Service/           PaymentService, SecretCipher, YooKassaClientFactory
src/Controller/        PaymentController (webhook/subscribe), LandingController (for-brands)
src/Controller/BrandLk/ BrandPaymentController, BrandOfferController, BrandOrderController, BrandDashboardController
src/Form/BrandLk/      SellerLegalEntityFormType, SellerPaymentAccountFormType
src/Twig/              BrandLkExtension (бейджи + sellerOfferPending)
templates/brand_lk/    payments/{index,legal_entity_form,account_form}, offer, dashboard, orders/show
templates/tailwind/landing/ for-brands (блок приёма платежей)
```
