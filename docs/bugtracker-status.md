# Bug Tracker — Статус исправлений

**Дата:** 2026-05-31  
**Ветка:** `task-20`  
**Источник:** `docs/bugtracker.md`

Условные обозначения: ✅ исправлено · 🔄 в работе · ⬜ не начато

---

## 🔴 Critical — безопасность (всё исправлено)

| ID | Суть | Статус |
|----|------|--------|
| BUG-01 | YooKassa webhook подделываем | ✅ IP allowlist + getPaymentInfo |
| BUG-02 | Сумма/валюта не сверяются | ✅ verified against Payment record |
| BUG-03 | `/payment/subscribe` без auth | ✅ IsGranted + ownership + CSRF |
| BUG-04 | Email-верификация не enforced | ✅ gate on checkout |
| BUG-05 | Смена email не сбрасывает verification | ✅ emailVerifiedAt = null + resend |
| BUG-06 | Инвайт — захват бренда (email mismatch) | ✅ проверка `$invite->getEmail() === $user->getUserIdentifier()` |

## 🟠 Сломанные потоки (всё исправлено)

| ID | Суть | Статус |
|----|------|--------|
| BUG-07 | Страница уведомлений ЛК падает | ✅ EVENT_TYPES/CHANNELS + fix `bool` precedence + `countUnread` |
| BUG-08 | `BrandInvite` import missing | ✅ use statement |
| BUG-09 | `payment.subscription_id` NOT NULL → 500 | ✅ nullable |
| BUG-10 | Мультибрендовый checkout теряет заказы | ✅ `createOrderPayment` принимает массив заказов |
| BUG-11 | Корзина очищается до оплаты | ✅ очистка после получения paymentUrl; success-страница; webhook |
| BUG-12 | Менеджер удаляет участников | ✅ owner gate |
| BUG-13 | `/verify-email/resend` route shadowing | ✅ resend before `{token}` |
| BUG-14 | Статус Payment откатывается | ✅ terminal status guards |
| BUG-15 | Сортировка медиа — no-op | ✅ sortOrder field + fix controller + ORDER BY |

## 🔵 Уведомления (всё исправлено)

| ID | Суть | Статус |
|----|------|--------|
| NOTIF-01 | Покупателю нет подтверждения заказа | ✅ dispatch в CheckoutController + template `order_confirmation.html.twig` |
| NOTIF-02 | Нет уведомлений об оплате | ✅ dispatcher in PaymentService; buyer notified on paid/failed |
| NOTIF-03 | Нет команды истечения подписок | ✅ `app:subscription:expire` command |
| NOTIF-04 | Принятие инвайта не уведомляет | ✅ dispatch to invited_by in InviteAcceptController |
| NOTIF-05 | Заявки на бренд мимо dispatcher | ✅ BrandClaimController + BrandClaimAdminController через dispatcher |
| NOTIF-06 | Push-канал не отправляется | ✅ удалён CHANNEL_PUSH + channelPush field |

## ⚪ Нестыковки

| ID | Суть | Статус |
|----|------|--------|
| INC-01 | NotificationSettings инертны (нет UI у покупателей) | ✅ добавлен `account/notification_settings.html.twig` + роут |
| INC-02 | Telegram deep-link не реализован | ✅ `telegramLinkToken` поле + миграция + `/start <token>` обработка |
| INC-03 | notifier.yaml мёртвый конфиг | ✅ удалён |
| INC-04 | ROLE_BRAND_OWNER не выдаётся | ✅ выставляется при owner-роли в InviteAccept + BrandClaim approve |
| INC-05 | Лимиты тарифа не enforced | ✅ проверка max_products/max_images в BrandProduct + BrandMedia |
| INC-06 | isActive() игнорирует период | ✅ проверяет `currentPeriodEnd >= now`; при оплате +1 месяц |
| INC-07 | Сток не списывается/не возвращается | ✅ clamp qty + decrement на checkout + return на cancel |
| INC-08 | Гостевая корзина не мёржится при логине | ✅ merge в LoginSuccessHandler |
| INC-09 | Нет login throttling | ✅ max_attempts: 5 |
| INC-10 | Коллизия номера заказа + нет транзакции | ✅ retry loop + wrapInTransaction |
| INC-11 | Нет CSRF на ручных формах | ✅ 11 critical handlers + templates |
| INC-12 | Нет MIME/размера валидации uploads | ✅ Assert\Image на upload endpoints (JPEG/PNG/WebP, 5MB) |
| INC-13 | Float round-trip денег | ⬜ не начато |
| INC-14 | Min длина пароля непоследовательна | ✅ унифицировано 8 |
| INC-15 | Нет идемпотентного ключа YooKassa | ✅ |
| INC-16 | Переключатель активного бренда | ⬜ не начато |
| INC-17 | Роль в инвайте — свободная строка | ✅ validated against allowed values |
| INC-18 | Dispatcher флашит внутри транзакции checkout | ✅ flush удалён из createInApp() |
| INC-19 | Webhook глотает ошибки → 200 | ✅ 500 on failure + refund handling |

---

## Сводка

| Категория | Всего | Исправлено | Осталось |
|-----------|-------|-----------|----------|
| 🔴 Critical | 6 | 6 | 0 |
| 🟠 Сломанные | 9 | 9 | 0 |
| 🔵 NOTIF | 6 | 6 | 0 |
| ⚪ INC | 19 | 17 | 2 |
| **Итого** | **40** | **38** | **2** |

**Осталось:** INC-13 (float money), INC-16 (brand switcher) — низкий приоритет.
