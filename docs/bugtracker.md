# WEARBASE — Bug Tracker (ЛК, уведомления, тарифы/биллинг)

**Создан:** 2026-05-31
**Источник:** аудит нового функционала (ЛК брендов и пользователей, уведомления, тарифы/подписки, корзина/checkout, оплата YooKassa).
**Назначение:** передача агенту-исполнителю для исправления. Каждый пункт самодостаточен: файл:строка, суть, причина, фикс, как проверить.

> ⚠️ Перед исправлением: ветка `task-20`. Миграции идемпотентны (`CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`), новые писать через `make:migration` или вручную по образцу `migrations/Version20260524_*.php`. Никогда не использовать `doctrine:schema:update`. Public dir — `public_html/`, а не `public/`.

## Карта подсистем (ориентир для исполнителя)

| Область | Ключевые файлы |
|---|---|
| Уведомления | `src/Notification/{NotificationDispatcher,EmailNotifier,TelegramNotifier}.php`, `src/Entity/{Notification,NotificationSettings}.php`, `config/packages/notifier.yaml`, `templates/emails/` |
| Тарифы/подписки/оплата | `src/Entity/{Tariff,Subscription,Payment}.php`, `src/Service/{PaymentService,SubscriptionFactory}.php`, `src/Controller/PaymentController.php`, `migrations/Version20260524_{billing,yookassa}.php` |
| ЛК бренда | `src/Controller/BrandLk/*`, `templates/brand_lk/*`, `src/Entity/{BrandUser,BrandInvite,BrandClaim}.php` |
| Аккаунт/Auth | `src/Controller/Account/*`, `src/Controller/Auth/*`, `src/Entity/User.php` |
| Корзина/Checkout | `src/Controller/Cart/*`, `src/Entity/{Cart,CartItem,Order,OrderItem,OrderStatusHistory}.php` |
| Доступ | `config/packages/security.yaml` |

---

# 🔴 CRITICAL — безопасность (приоритет 1)

## BUG-01 — YooKassa webhook полностью подделываем
- **Severity:** critical
- **Файлы:** `src/Controller/PaymentController.php:20-29`, `src/Service/PaymentService.php:123-196`
- **Проблема:** эндпоинт `^/payment/yookassa/webhook` объявлен `PUBLIC_ACCESS` (`config/packages/security.yaml:73`). `handleNotification()` парсит сырое тело через `NotificationFactory` и **доверяет полю `status` из тела**. Нет IP-allowlist, нет перепроверки через API. Зная `gatewayPaymentId`, любой POST'ит `{"event":"payment.succeeded","object":{"id":"<id>","status":"succeeded","metadata":{"payment_type":"subscription"}}}` → платёж помечается `paid`, подписка активируется. Для `order`: заказ ставится `PAYMENT_PAID` **даже если строки `Payment` нет** (`PaymentService.php:188-192`).
- **Важно:** YooKassa **не подписывает** webhooks (нет HMAC) — «проверка подписи» не решение.
- **Фикс:**
  1. Ограничить маршрут по официальным IP-диапазонам YooKassa (на уровне nginx или проверкой `$request->getClientIp()`).
  2. В `handleNotification` игнорировать `status`/`amount` из тела; перезапрашивать авторитетный статус: `$this->client->getPaymentInfo($paymentId)` и действовать только по нему.
  3. Если `Payment` по `gatewayPaymentId` не найден — bail (ничего не менять, лог).
- **Проверка:** поддельный POST с произвольным `gatewayPaymentId` не меняет состояние; реальный callback после `getPaymentInfo` корректно проводит оплату.

## BUG-02 — Сумма/валюта webhook не сверяются с ожидаемым Payment
- **Severity:** critical
- **Файлы:** `src/Service/PaymentService.php:150-167` (subscription), `:169-196` (order)
- **Проблема:** оба хендлера ищут `Payment` по `gatewayPaymentId` и действуют только по `status`. `amount.value`/`amount.currency` из нотификации не сверяются с `Payment::getAmount()`/`getCurrency()`. Эксплойт: создать реальный платёж за подписку, забрать `gatewayPaymentId` из confirmation URL, бросить оплату, прислать поддельный `succeeded` → бесплатный Premium.
- **Фикс:** после `getPaymentInfo` (BUG-01) проверять `confirmed.amount == Payment.amount && currency == Payment.currency`, иначе не проводить.
- **Проверка:** callback с суммой, не равной `Payment.amount`, отклоняется.

## BUG-03 — `/payment/subscribe/{id}` без auth и проверки владения
- **Severity:** critical
- **Файлы:** `src/Controller/PaymentController.php:31-54`, `config/packages/security.yaml:64-80`
- **Проблема:** `^/payment` не покрыт ни одним `access_control` кроме webhook → `subscribe/{id}` фактически публичный. Подписка грузится по сырому id без проверки, что текущий юзер — менеджер `$subscription->getBrand()`. Также state-changing POST без явной проверки CSRF.
- **Фикс:**
  1. `security.yaml`: добавить `- { path: ^/payment/subscribe, roles: ROLE_BRAND_MANAGER }`.
  2. В экшене проверить владение брендом (voter или ручная проверка `BrandUser`).
  3. Включить CSRF на форму инициации оплаты.
- **Проверка:** юзер A не может инициировать оплату подписки бренда юзера B.

## BUG-04 — Email-верификация нигде не enforced
- **Severity:** critical
- **Файлы:** `src/Controller/Auth/RegisterController.php:97-101`, `config/packages/security.yaml:79-80`, `src/Entity/User.php:227`
- **Проблема:** после регистрации юзер логинится сразу; ни `access_control`, ни контроллеры не проверяют `isEmailVerified()`. Весь flow верификации декоративный — неподтверждённый юзер логинится и оформляет заказ.
- **Фикс:** добавить проверку `isVerified` (event subscriber/voter), гейтить как минимум `/checkout`; либо блокировать логин до верификации.
- **Проверка:** неподтверждённый аккаунт не доходит до оформления заказа.

## BUG-05 — Смена email в профиле не сбрасывает статус верификации
- **Severity:** bug (security)
- **Файлы:** `src/Controller/Account/AccountController.php:50-69`, `src/Form/Account/ProfileFormType.php`
- **Проблема:** смена email флашится без сброса `emailVerifiedAt` и без нового токена → аккаунт «верифицирован» на неподтверждённом адресе.
- **Фикс:** при изменении email обнулять `emailVerifiedAt`, генерировать токен, слать `verify_email`.
- **Проверка:** после смены email статус снова unverified, письмо отправлено.

## BUG-06 — Приём инвайта игнорирует email приглашённого → захват бренда
- **Severity:** critical *(подтверждено 3 агентами)*
- **Файлы:** `src/Controller/Auth/InviteAcceptController.php:30-51`
- **Проблема:** `accept()` привязывает инвайт к текущему залогиненному юзеру (`$user = $this->getUser()`) и **никогда не сверяет `$invite->getEmail()` с email юзера**. Любой авторизованный, получивший ссылку, входит в чужой бренд с ролью из инвайта + `ROLE_BRAND_MANAGER` (`:46-58`).
- **Фикс:** до приёма отклонять, если `strtolower($invite->getEmail()) !== strtolower($user->getUserIdentifier())`.
- **Проверка:** инвайт нельзя принять с другого аккаунта.

---

# 🟠 Сломанные потоки и фатальные баги (приоритет 2)

## BUG-07 — Страница уведомлений ЛК падает всегда (undefined constants)
- **Severity:** bug (fatal) *(подтверждено 2 агентами)*
- **Файлы:** `src/Controller/BrandLk/BrandNotificationsController.php:34,43,56,57`
- **Проблема:** обращается к `self::EVENT_TYPES` и `self::CHANNELS`, которых нет ни в классе, ни в родителе `BrandDashboardController`. Фатал на GET **и** POST → `/brand/notifications` не открывается. **Следствие:** это единственный writer `NotificationSettings` → настройки нельзя создать вообще (см. BUG-19).
- **Фикс:** определить `EVENT_TYPES` (map тип события → лейбл) и `CHANNELS` на контроллере; имена каналов должны совпадать с сеттерами `NotificationSettings` (см. `'set'.ucfirst($channel)` на `:46`).
- **Доп. баг рядом (`:44`):** `$value = (bool) $request->request->get('settings')[$eventType][$channel] ?? false;` — приоритет операторов: `(bool)` связывается раньше `??`, при отсутствии ключа `null[$eventType]` даёт warning. Исправить на `(bool)($request->request->all('settings')[$eventType][$channel] ?? false)` + использовать `->all()`.
- **Доп. (`:59`):** `'unreadCount' => 0` захардкожен — использовать `NotificationRepository::countUnread()`.
- **Проверка:** страница рендерится, чекбоксы сохраняются в `notification_settings`.

## BUG-08 — Отправка инвайта команды падает (нет импорта класса)
- **Severity:** bug (fatal)
- **Файлы:** `src/Controller/BrandLk/BrandTeamController.php:56`
- **Проблема:** `new BrandInvite()` без `use App\Entity\BrandInvite;` → PHP резолвит в `App\Controller\BrandLk\BrandInvite` → `Class not found` при каждой отправке инвайта.
- **Фикс:** добавить `use App\Entity\BrandInvite;`.
- **Проверка:** owner отправляет инвайт без ошибки.

## BUG-09 — Оплата заказов картой невозможна (NOT NULL vs nullable → 500)
- **Severity:** critical (broken flow)
- **Файлы:** `migrations/Version20260524_billing.php:62`, `src/Entity/Payment.php:25-27`, `src/Service/PaymentService.php:84-118`
- **Проблема:** миграция объявляет `payment.subscription_id INT NOT NULL`, а entity мапит `nullable: true`, и `createOrderPayment` не вызывает `setSubscription()`. `flush()` (`:110`) бросает NOT NULL violation, проглатывается `catch (\Throwable)` (`:113`), затем `markAsFailed()`+`flush()` на той же строке с null `subscription_id` → повторный бросок **uncaught** → HTTP 500. Оплата заказов картой не работает совсем.
- **Фикс:** сделать `payment.subscription_id` nullable новой миграцией; не делать повторный flush невалидной entity в catch.
- **Проверка:** оплата заказа картой создаёт `Payment` и редиректит на YooKassa.

## BUG-10 — Мультибрендовый checkout теряет заказы
- **Severity:** bug *(подтверждено 2 агентами)*
- **Файлы:** `src/Controller/Cart/CheckoutController.php:228-242` (+ `:220-223` очистка корзины)
- **Проблема:** на корзину из N брендов создаётся N заказов (`$createdOrders[]`), но в оплату идёт только `$createdOrders[0]` (`:230`). Остальные остаются `pending`, корзина уже очищена, юзера редиректит на «успех» — остальные заказы фактически бесплатны/осиротевшие.
- **Фикс:** либо единый агрегированный платёж на все заказы (и в webhook проводить все заказы с этим `gatewayPaymentId`), либо цикл с оплатой каждого.
- **Проверка:** корзина из 2 брендов → оплачиваются оба заказа.

## BUG-11 — Корзина очищается до подтверждения оплаты
- **Severity:** bug
- **Файлы:** `src/Controller/Cart/CheckoutController.php:220-225`
- **Проблема:** все items удаляются в `confirm()` до редиректа на шлюз. Отмена/неуспех оплаты → корзина пуста, заказы висят `pending`.
- **Фикс:** чистить корзину только после `succeeded` (в webhook), либо восстанавливать при неуспехе.
- **Проверка:** брошенная оплата оставляет корзину.

## BUG-12 — Менеджер может удалять других участников команды
- **Severity:** bug (privilege)
- **Файлы:** `src/Controller/BrandLk/BrandTeamController.php:82-107`
- **Проблема:** `removeMember()` проверяет только: участник в бренде (`:86`), ≠ self (`:92`), ≠ owner (`:97`). Не проверяет, что текущий юзер — owner (в отличие от `invite()` на `:43-48`). Менеджер удаляет других менеджеров.
- **Фикс:** добавить owner-гейт как в `invite()` (загрузить `BrandUser` текущего юзера, требовать `ROLE_OWNER`).
- **Проверка:** менеджер получает 403 на removeMember.

## BUG-13 — `/verify-email/resend` недостижим (route shadowing)
- **Severity:** bug
- **Файлы:** `src/Controller/Auth/EmailVerificationController.php:16` (`/verify-email/{token}` без requirements) против `:39` (`/verify-email/resend`)
- **Проблема:** первый маршрут без `{token}`-requirements перехватывает `resend` как `token="resend"` → «Недействительная ссылка». Resend не работает.
- **Фикс:** добавить `requirements: ['token' => '[0-9a-f]{64}']` к verify-маршруту (или объявить `resend` раньше).
- **Проверка:** `/verify-email/resend` шлёт письмо повторно.

## BUG-14 — Статус Payment откатывается succeeded→failed; не идемпотентно
- **Severity:** bug
- **Файлы:** `src/Entity/Payment.php:80-94`, `src/Service/PaymentService.php:155-196`
- **Проблема:** `markAsPaid`/`markAsFailed` без гардов; нет «succeeded терминальный». Поздний `canceled`/`failed` после `succeeded` переворачивает оплаченный платёж (и `Order.paymentStatus`), может деактивировать оплаченную подписку. Повторный `succeeded` ре-активирует и сбрасывает `paidAt`. Дублирующийся `setPaymentStatus` (`:181/189` и `:184/191`).
- **Фикс:** гард переходов — после `STATUS_PAID` игнорировать не-refund нотификации; no-op при уже целевом терминальном статусе; убрать дубль-блок.
- **Проверка:** повторный/поздний callback не меняет оплаченный платёж.

## BUG-15 — Сортировка медиа бренда — no-op
- **Severity:** bug
- **Файлы:** `src/Controller/BrandLk/BrandMediaController.php:74-95`
- **Проблема:** цикл с `break` без записи sort-значения (у `BrandImage` нет поля sort, см. коммент `:85-86`). `flush()` ничего не сохраняет — drag-reorder молча не работает.
- **Фикс:** добавить поле `sortOrder` в `BrandImage` (+ миграция) и писать его в цикле; либо убрать UI сортировки.
- **Проверка:** порядок медиа сохраняется после перетаскивания.

---

# 🔵 Отсутствующие уведомления (приоритет 3)

> **Контекст:** `NotificationSettings` мертвы целиком (см. BUG-19). После починки BUG-07 нужно дописать триггеры ниже. Транзакционные письма (`verify_email`, `reset_password`, `brand_invite`, `new_lead`) идут напрямую через `EmailNotifier` и работают — их трогать не нужно.

## NOTIF-01 — Покупателю нет подтверждения заказа
- **Файлы:** `src/Controller/Cart/CheckoutController.php:202-218`
- **Проблема:** при оформлении уведомляется только бренд (`TYPE_ORDER_NEW`). Покупатель не получает ничего; нет email-шаблона подтверждения заказа.
- **Фикс:** после создания заказа `dispatch($user, ...)` подтверждение (in-app + email) + добавить шаблон `templates/emails/order_confirmation.html.twig`.

## NOTIF-02 — Нет уведомлений об оплате (успех/провал)
- **Файлы:** `src/Service/PaymentService.php:150-196`
- **Проблема:** `handleOrderPayment()`/`handleSubscriptionPayment()` меняют статусы, но не шлют уведомлений ни покупателю (оплата прошла/не прошла), ни бренду (подписка активирована/просрочена). `NotificationDispatcher` даже не инжектится в сервис.
- **Фикс:** инжектить `NotificationDispatcher`; уведомлять покупателя `PAYMENT_PAID`/`PAYMENT_FAILED`, owner'а бренда — об активации/провале подписки.

## NOTIF-03 — Нет жизненного цикла подписок + нет команды истечения
- **Файлы:** `src/Entity/Subscription.php:18,20` (`STATUS_PAST_DUE`/`STATUS_EXPIRED` объявлены, но не выставляются нигде), `src/Command/` (команды нет)
- **Проблема:** ничто не переводит подписку в expired/past_due и не шлёт «триал истекает / платёж просрочен / подписка истекла». Триалы и оплаченные периоды не истекают.
- **Фикс:** создать `app:subscription:expire` (cron): находит подписки за `trialEndsAt`/`currentPeriodEnd`, меняет статус, шлёт уведомления. См. также BUG-21 (период не продлевается при оплате).

## NOTIF-04 — Принятие инвайта не уведомляет пригласившего
- **Файлы:** `src/Controller/Auth/InviteAcceptController.php:60-66`
- **Проблема:** при accept инвайт помечается принятым и создаётся `BrandUser`, но `$invite->getInvitedBy()` не уведомляется.
- **Фикс:** `dispatch($invite->getInvitedBy(), ...)` событие «инвайт принят».

## NOTIF-05 — Заявки на бренд идут мимо стека уведомлений
- **Файлы:** `src/Controller/BrandClaimController.php:216-274`, `src/Controller/Admin/BrandClaimAdminController.php:49-94`
- **Проблема:** уведомления о заявке (админу при submit, юзеру при approve/reject) шлются сырым `MailerInterface` с ручным HTML — мимо `EmailNotifier`/шаблонов/in-app записи/настроек/Telegram. В approve-HTML (`:231`) незаполненный `%%s/admin-claims` → литеральный `%s` в письме. Вторая ветка approve (`BrandClaimAdminController.php:49-94`) не шлёт юзеру **ничего**.
- **Фикс:** провести события заявки через `NotificationDispatcher` с шаблонами; консолидировать две ветки approve.

## NOTIF-06 — Канал push объявлен, но не отправляется
- **Файлы:** `src/Notification/NotificationDispatcher.php:38-58`, `src/Entity/NotificationSettings.php:35`, `src/Entity/Notification.php:28`
- **Проблема:** `NotificationSettings::channelPush` и `Notification::CHANNEL_PUSH` есть, но dispatcher обрабатывает только `inapp`/`email`/`telegram`. `channelPush` не читается нигде.
- **Фикс:** либо реализовать push, либо удалить поле/константу.

---

# ⚪ Нестыковки и недоделки (приоритет 4)

## INC-01 — `NotificationSettings` полностью инертны
- **Файлы:** `src/Notification/NotificationDispatcher.php:33`
- **Проблема:** единственный writer (`BrandNotificationsController`) сломан (BUG-07), у покупателей UI настроек нет → `findOneBy(...)` всегда `null` → каждый dispatch валится в дефолты (`inapp=true,email=true,telegram=false`). Настройки каналов не уважаются никогда.
- **Фикс:** после BUG-07 добавить UI настроек и покупателям (`src/Controller/Account/NotificationController.php` сейчас только list/mark-read).

## INC-02 — Telegram-канал недостижим для большинства
- **Файлы:** `src/Notification/NotificationDispatcher.php:52`, `src/Controller/Account/AccountController.php:260`, `src/Controller/TelegramController.php:35`
- **Проблема:** Telegram требует `getTelegramChatId()`; chat_id сохраняется только ручной вставкой в настройках безопасности; `/start` webhook лишь эхает chat_id, не связывая. С дефолтом `channelTelegram=false` (INC-01) Telegram не срабатывает практически никогда.
- **Фикс:** реализовать привязку через deep-link `/start <token>` в `TelegramController`.

## INC-03 — `notifier.yaml` настроен, но Symfony Notifier не используется
- **Файлы:** `config/packages/notifier.yaml:1-13`, `.env:50` (`ADMIN_EMAIL`)
- **Проблема:** chatter/telegram transport, channel_policy, `admin_recipients: admin@example.com` — но вся доставка через кастомные `EmailNotifier`/`TelegramNotifier`. Конфиг мёртв; `admin@example.com` конфликтует с реальным `ADMIN_EMAIL`.
- **Фикс:** удалить мёртвый конфиг или мигрировать на Symfony Notifier; убрать placeholder.

## INC-04 — `ROLE_BRAND_OWNER` не выдаётся нигде (мёртвая роль)
- **Файлы:** `config/packages/security.yaml:8`, `src/Controller/Auth/InviteAcceptController.php:55`, `src/Controller/BrandClaimController.php:147-150`
- **Проблема:** оба места выдачи добавляют только `ROLE_BRAND_MANAGER` (даже при создании owner-`BrandUser`). Различие owner/manager живёт лишь в `BrandUser.role`; `IsGranted('ROLE_BRAND_OWNER')` никогда не пройдёт.
- **Фикс:** выдавать `ROLE_BRAND_OWNER` при owner-роли, либо ввести voter, читающий `BrandUser.role`.

## INC-05 — Лимиты тарифа не enforced
- **Файлы:** `src/Entity/Tariff.php:41-45,98-102`, потребитель только `templates/brand_lk/settings.html.twig:43-44` (отображение)
- **Проблема:** `getMaxProducts()`/`getMaxImages()` не используются в создании товаров/загрузке фото. Free-тариф (лимит 10) может лить безлимитно.
- **Фикс:** проверять лимиты в `BrandProductController::new`/`uploadImages` и `BrandMediaController::upload` против активной подписки/тарифа бренда.

## INC-06 — Подписка: `isActive()` игнорирует период; продление не реализовано
- **Файлы:** `src/Entity/Subscription.php:114-117`, `:51` (`autoRenew`), `src/Service/PaymentService.php:157-160`
- **Проблема:** `isActive()` проверяет только `status`, не `currentPeriodEnd`. Активация гейтится на `isOnTrial()`; оплата не продвигает `currentPeriodStart/End` (период не продлевается); оплата уже-active/past_due/cancelled ничего не делает; `autoRenew` не читается нигде.
- **Фикс:** `isActive()` должен требовать `currentPeriodEnd >= now`; при успешной оплате продлевать период (+1 месяц) независимо от триала; реализовать `autoRenew`.

## INC-07 — Сток не списывается при заказе, не валидируется, не возвращается
- **Файлы:** `src/Controller/Cart/CartController.php:49-66`, `src/Controller/Cart/CheckoutController.php:162-200`, `src/Controller/Account/AccountController.php:109-130`
- **Проблема:** `add` проверяет только `isInStock()` (>0), не ограничивает `qty` по `getStockQty()`; checkout не перепроверяет сток и не декрементит `ProductVariant::stockQty`; отмена не возвращает сток → оверселлинг.
- **Фикс:** клампить qty по доступному стоку на add/update; декрементить сток в транзакции при создании заказа; возвращать при отмене/возврате.

## INC-08 — Гостевая корзина не мёржится при логине
- **Файлы:** `src/Security/LoginSuccessHandler.php`, `src/Controller/Cart/CartController.php:132-161`
- **Проблема:** success handler не мёржит; `getOrCreateCart` ищет корзину по `user`, игнорируя гостевую по `sessionId`. Гость, наполнивший корзину, теряет её при логине (осиротевшая `Cart` остаётся в БД).
- **Фикс:** в success handler найти гостевую корзину по старому session id и смёржить items в пользовательскую.

## INC-09 — Нет login throttling
- **Файлы:** `config/packages/security.yaml:47-62` (firewall `main`)
- **Проблема:** нет `login_throttling` → перебор паролей не ограничен.
- **Фикс:** `login_throttling: { max_attempts: 5 }` на firewall `main`.

## INC-10 — Генерация номера заказа: коллизии + нет транзакции
- **Файлы:** `src/Controller/Cart/CheckoutController.php:332-335` (+ unique-constraint `src/Entity/Order.php:77`)
- **Проблема:** `random_int(1,99999)` без retry на коллизию → unique violation абортит весь `confirm()`. Создание N заказов без транзакции — частичный сбой оставляет корзину очищенной без заказов.
- **Фикс:** обернуть создание заказов в транзакцию (`em->wrapInTransaction`); генерировать номер с retry-on-collision или через sequence.

## INC-11 — Нет CSRF на руколепных формах/`fetch`
- **Severity:** bug (security) *(подтверждено по двум подсистемам)*
- **Файлы (ЛК):** `templates/brand_lk/team/index.html.twig:40,67`, `BrandOrderController.php:54,91`, `BrandProductController.php:173,207,227,251`, `BrandMediaController.php:28,57,74`
- **Файлы (аккаунт/корзина):** `templates/cart/index.html.twig:69,128`, `templates/account/addresses.html.twig:44,47`, `templates/account/order_show.html.twig:170`, `templates/account/security.html.twig:8` (`AccountController::security:244-267`), `templates/checkout/index.html.twig:8`
- **Проблема:** ручные `<form method="post">`/`fetch` без `_token`; контроллеры читают `$request->request->get(...)` без `isCsrfTokenValid()`. (Формы через `createForm` — ок, CSRF авто.)
- **Фикс:** добавить `csrf_token('...')` в каждую форму + `isCsrfTokenValid()` в контроллере.

## INC-12 — Нет валидации MIME/размера загружаемых картинок
- **Файлы:** `src/Entity/ProductImage.php`, `src/Entity/BrandImage.php`, `src/Form/BrandLk/BrandProfileFormType.php:46`, JSON-эндпоинты `BrandProductController.php:173`, `BrandMediaController.php:28`
- **Проблема:** Vich-поля без `Assert\Image`/`Assert\File`; JSON-эндпоинты сохраняют любой файл без проверки типа/размера под web-root.
- **Фикс:** `Assert\Image` (или `Assert\File` с mimeTypes/maxSize) на поля/эндпоинты загрузки.

## INC-13 — Деньги: float round-trip, RUB захардкожен
- **Файлы:** `src/Service/PaymentService.php:79,85,91,41,86`, `src/Controller/Cart/CheckoutController.php:190,230`
- **Проблема:** `createOrderPayment` делает `(float)$order->getTotalAmount()`, хранит `setAmount((string)$amount)` (теряет `.00`/float-артефакты), отдельно `number_format($amount,2)` для YooKassa → хранимая и отправляемая суммы форматируются по-разному, обе через float. RUB захардкожен при мультивалютной системе.
- **Фикс:** держать суммы как decimal-строки end-to-end (без float); форматировать один раз `number_format` для шлюза и хранения.

## INC-14 — Min длина пароля непоследовательна
- **Файлы:** `src/Controller/Account/AccountController.php:253` (6), против 8 в Registration/Reset/Profile форм-типах
- **Фикс:** унифицировать до 8.

## INC-15 — Нет идемпотентного ключа при создании платежа YooKassa
- **Файлы:** `src/Service/PaymentService.php:44,89`
- **Проблема:** `createPayment($params)` без второго аргумента → SDK генерит свежий UUID на каждый вызов → повторный/двойной submit создаёт второй платёж и вторую строку `Payment`.
- **Фикс:** передавать стабильный ключ: `createPayment($params, "sub-{$subId}-{$periodStart}")` / `"order-{$orderNumber}"`.

## INC-16 — `getActiveBrand()` берёт произвольный бренд; нет переключателя
- **Файлы:** `src/Controller/BrandLk/BrandDashboardController.php:47-59`
- **Проблема:** `findOneBy(['user'=>$user])` без сортировки → юзер с несколькими брендами заперт в произвольном; переключателя нет (коммент `:44-45`).
- **Фикс:** добавить выбор/переключатель активного бренда (например, в сессии).

## INC-17 — Роль в инвайте — невалидируемая свободная строка
- **Файлы:** `src/Controller/BrandLk/BrandTeamController.php:59`
- **Проблема:** `setRole($request->request->get('role', BrandUser::ROLE_MANAGER))` принимает любую строку; копируется в `BrandUser.role` на accept (`InviteAcceptController.php:49`).
- **Фикс:** валидировать против `[BrandUser::ROLE_OWNER, BrandUser::ROLE_MANAGER]`.

## INC-18 — `NotificationDispatcher` флашит внутри транзакции checkout
- **Файлы:** `src/Notification/NotificationDispatcher.php:44-58,75`, `src/Controller/Cart/CheckoutController.php:208,225`
- **Проблема:** `createInApp()` вызывает `$em->flush()` сам; в `confirm` dispatcher вызывается в per-brand цикле до финального flush — коммит полузаполненного заказа, `data['order_id']` может быть null, частичный сбой оставляет несогласованное состояние.
- **Фикс:** dispatcher не должен флашить (отдать контроль вызывающему), либо dispatch после финального flush.

## INC-19 — Webhook глотает ошибки и всегда отвечает 200
- **Файлы:** `src/Service/PaymentService.php:145` (`catch(\Throwable){return false;}`), `src/Controller/PaymentController.php:28`
- **Проблема:** контроллер всегда возвращает `200 {ok:true}` → YooKassa считает доставку успешной и не ретраит реально упавшую обработку; refund-события (`payment.refund.succeeded`) не обрабатываются (`handleNotification` игнорирует `$event`, ключ только на `status`); `STATUS_REFUNDED` объявлен, но не используется.
- **Фикс:** возвращать 5xx при реальной ошибке обработки (чтобы YooKassa ретраил); добавить обработку refund-события.

---

# Проверено и НЕ уязвимо (не трогать)

- IDOR заказов/товаров/медиа в ЛК закрыт (`denyUnlessOwns`: `BrandProductController`/`BrandOrderController`/`BrandMediaController::delete`).
- Импорт товаров корректно скоупится по бренду (`ProductImportService.php:233-238`).
- Заказы покупателя скоупятся по `customer` (`AccountController.php:97,114`); адреса проверяют владельца (`:179,204,222`).
- Цена `OrderItem` снимается с БД-варианта (`OrderItem::fillFromVariant:114-124`) — не доверяется клиенту; subtotal/total пересчитываются на сервере.
- Токены: `bin2hex(random_bytes(32))` (CSPRNG, 256-бит); reset пароля — TTL 1ч + одноразовый; forgot-password не раскрывает существование аккаунта.
- `BrandClaim` маршруты не greedy; claim status/admin-действия имеют проверки владения/роли.

---

# Рекомендованный порядок исправления

1. **Безопасность платежей:** BUG-01, BUG-02, BUG-09, INC-15, INC-19.
2. **Захват/доступ:** BUG-03, BUG-06, BUG-04, BUG-05, INC-11.
3. **Сломанные потоки:** BUG-07, BUG-08, BUG-10, BUG-11, BUG-12, BUG-13, BUG-14, BUG-15.
4. **Уведомления:** BUG-07 → INC-01/02 → NOTIF-01..06.
5. **Подписки/тарифы:** NOTIF-03, INC-05, INC-06.
6. **Остальное:** INC-07..18.

> После каждого пункта: `php bin/console cache:clear`; релевантные `php bin/phpunit`; для платёжных — ручной тест webhook с поддельным телом (должен отклоняться).
