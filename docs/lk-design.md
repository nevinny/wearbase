# Личные кабинеты: ЛК Бренда + ЛК Клиента
## Архитектурный проект для WEARBASE

---

## 1. Контекст и стек

- **Symfony 7.3**, PHP 8.2+, Doctrine ORM, MySQL
- **EasyAdmin** — уже используется для /admin
- **symfony/notifier + symfony/mailer** — уже установлены
- **symfony/ux-turbo + stimulus** — уже установлены (используем для live-обновлений)
- **vich/uploader-bundle** — уже используется для загрузки фото

Уже есть:
- `Nevinny\AdminCoreBundle\Entity\User` — только для /admin
- `App\Entity\Brand` — каталог брендов с логотипом, описанием, стилями, размерами, изображениями, ссылками
- `App\Entity\Product` — только price + image + brand (нужно расширять)
- `BrandStyle`, `BrandSize`, `BrandAudience`, `BrandTier` — справочники

---

## 2. Роли и пользователи

### App\Entity\User (новый — front-end пользователи)

Отдельная сущность от admin User. Один человек может быть одновременно покупателем и менеджером бренда.

```
Роли:
ROLE_USER        — базовая (все авторизованные)
ROLE_CUSTOMER    — покупатель (назначается автоматически при регистрации)
ROLE_BRAND_MANAGER — менеджер бренда (назначается при принятии приглашения)
ROLE_BRAND_OWNER   — владелец/создатель бренда (может приглашать менеджеров)
```

**Важно**: у одного пользователя может быть несколько ролей. Покупатель может стать менеджером своего бренда — не нужно два аккаунта.

### BrandUser (pivot: пользователь ↔ бренд)

Связывает конкретного человека с конкретным брендом. Один бренд может иметь несколько менеджеров. Один человек может управлять несколькими брендами.

---

## 3. Новые сущности (Entity)

### 3.1 User
```
id, email (unique), password, roles[],
firstName, lastName, phone,
avatar, avatarFile (Vich),
telegramChatId (для уведомлений),
emailVerifiedAt, status (active|banned|pending),
createdAt, updatedAt
```

### 3.2 BrandUser
```
id,
user → User,
brand → Brand,
role (owner | manager),
invitedBy → User (nullable),
invitedAt, acceptedAt (nullable — pending invite)
```

### 3.3 BrandInvite
```
id,
brand → Brand,
invitedByUser → User,
email, token (unique, uuid),
role (owner | manager),
expiresAt, acceptedAt (nullable)
```

### 3.4 ProductCategory (новый справочник)
```
id, slug (unique), title, parent → self (nullable),
icon, ord, status
```
Примеры: Верхняя одежда / Куртки, Толстовки / Худи, Брюки / Джинсы

### 3.5 Product (расширение существующего)
Добавить к текущим полям (price, image, brand, description):
```
+ title (string)
+ slug (unique string)
+ category → ProductCategory
+ gender (enum: men | women | unisex | kids)
+ styles → BrandStyle[] (ManyToMany)
+ status (enum: draft | active | archived)
+ metaTitle, metaDescription
+ createdAt, updatedAt
```

### 3.6 ProductVariant (SKU)
```
id,
product → Product,
sku (string, уникальный в рамках бренда),
size → BrandSize (nullable),
color (string, напр. "Чёрный"),
colorHex (string, напр. "#000000"),
price (decimal),
comparePrice (decimal nullable — зачёркнутая цена),
stockQty (int, default 0),
weight (float, г — для расчёта доставки),
status (active | inactive)
```

### 3.7 ProductImage
```
id,
product → Product,
variant → ProductVariant (nullable — общее фото или фото варианта),
filename (string, Vich),
imageFile (File, Vich),
sort (int, default 0),
isMain (bool, default false)
```

### 3.8 Address (адреса покупателя)
```
id,
user → User,
label (string, "Домашний" / "Рабочий"),
fullName, phone,
country (default "RU"), city, street,
building, apartment, zip,
isDefault (bool)
```

### 3.9 Cart
```
id,
user → User (nullable — гостевая корзина),
sessionId (string, для гостей),
createdAt, updatedAt
```

### 3.10 CartItem
```
id,
cart → Cart,
variant → ProductVariant,
qty (int),
addedAt
```

### 3.11 Order
```
id,
orderNumber (string unique, напр. "WB-2026-00142"),
customer → User,
brand → Brand,
status (enum, см. §6),
paymentStatus (enum: pending | paid | refunded | failed),
paymentMethod (enum: card_online | sbp | upon_receipt),
deliveryMethod (enum: courier | pickup | cdek | boxberry | pochta),
trackingNumber (string nullable),
shippingAddress (JSON: {fullName, phone, city, street, building, apartment, zip}),
subtotal, shippingCost, discountAmount, totalAmount (decimal),
currency (default "RUB"),
customerNote (text nullable),
adminNote (text nullable),
createdAt, updatedAt, completedAt
```

**Важно**: заказ всегда относится к одному бренду. Если покупатель купил товары двух брендов — создаётся два заказа. Это упрощает логику уведомлений и логистики.

### 3.12 OrderItem
```
id,
order → Order,
variant → ProductVariant (nullable — на случай удалённого товара),
productTitle (string — snapshot названия),
variantTitle (string — snapshot "XL / Чёрный"),
sku (string snapshot),
price (decimal snapshot),
qty (int),
total (decimal)
```

### 3.13 OrderStatusHistory
```
id,
order → Order,
fromStatus (string nullable),
toStatus (string),
comment (text nullable),
createdBy → User (nullable),
createdAt
```

### 3.14 Notification
```
id,
recipient → User,
type (string: order_new | order_status_changed | brand_invite | product_review | ...),
title, body (text),
data (JSON — ссылки, id объектов),
isRead (bool),
channel (enum: inapp | email | telegram),
createdAt, readAt (nullable)
```

### 3.15 NotificationSettings
```
id,
user → User,
eventType (string),
channelEmail (bool default true),
channelTelegram (bool default false),
channelInapp (bool default true)
```

---

## 4. ЛК Бренда (Seller Dashboard)

### 4.1 Регистрация и подключение бренда

**Сценарий A — Claim существующего бренда:**
1. Пользователь заходит на `/brands/{slug}` — видит кнопку "Это ваш бренд? Управляйте им"
2. Кликает → форма заявки: email, имя, должность, ссылка на соцсеть для верификации
3. Платформа (admin) верифицирует → присваивает роль owner

**Сценарий B — Добавление нового бренда:**
1. `/brand/new` — форма создания бренда
2. Бренд создаётся со статусом `pending` (скрыт из каталога)
3. После модерации → `active`

### 4.2 Структура роутинга

```
/brand/login                    — вход (отдельный firewall)
/brand/register                 — регистрация

/brand/dashboard                — главный экран (метрики)
/brand/profile                  — публичный профиль бренда
/brand/media                    — галерея фото бренда
/brand/settings                 — настройки аккаунта
/brand/team                     — команда (invite / remove)
/brand/team/invite              — пригласить менеджера
/brand/notifications            — настройки уведомлений

/brand/products                 — список товаров
/brand/products/new             — создать товар (step 1: основное)
/brand/products/{id}/edit       — редактировать
/brand/products/{id}/variants   — управление вариантами (размеры/цвета/остатки)
/brand/products/{id}/images     — загрузка фото
/brand/products/{id}/publish    — опубликовать / снять с публикации

/brand/orders                   — входящие заказы
/brand/orders/{id}              — детали заказа + смена статуса
/brand/orders/{id}/ship         — POST: ввод трекинга + статус "shipped"

/brand/analytics                — статистика (просмотры, заказы, топ-товары)
```

### 4.3 Главный экран (Dashboard)

Виджеты:
- Новые заказы сегодня (badge + список)
- Выручка за 7 / 30 дней (график)
- Товары с нулевым остатком (требуют внимания)
- Незаполненные поля профиля (прогресс-бар — мотивирует заполнить)
- Последние действия (лента)

### 4.4 Управление профилем бренда

Вкладки:
1. **Основное** — название, slug (публичная ссылка `/brands/{slug}`), город, описание, анонс
2. **Медиа** — логотип + галерея (drag&drop, сортировка)
3. **Контакты** — email, телефон, адрес шоурума
4. **Ссылки** — Instagram, Telegram, VK, сайт, Ozon, WB (BrandLink)
5. **Стили** — теги из BrandStyle (streetwear, casual, sport и т.д.)
6. **Аудитория** — мужская / женская / детская (BrandAudience)

### 4.5 Добавление товара (многошаговая форма)

**Шаг 1 / Основное**
```
Название (title)
Категория (ProductCategory — select с деревом)
Пол (gender — radio)
Краткое описание
Подробное описание (textarea/wysiwyg)
Стили (multiselect теги)
Статус (сохранить как черновик / сразу публиковать)
```

**Шаг 2 / Варианты (SKU)**

Таблица вариантов. Строка = один вариант:
```
| Размер | Цвет       | Цена    | Старая цена | Остаток | Артикул   |
|--------|------------|---------|-------------|---------|-----------|
| XS     | Чёрный #000| 4 900 ₽ | —           | 5       | SB-001-XS |
| S      | Чёрный #000| 4 900 ₽ | —           | 12      | SB-001-S  |
| S      | Белый #FFF | 4 900 ₽ | 5 900 ₽     | 3       | SB-001-SW |
```

Кнопка "Добавить вариант" — inline. Быстрое копирование строки.
Кнопка "Генерировать все размеры" — создать строки для всей сетки размеров бренда.

**Шаг 3 / Фотографии**

Drag&drop multi-upload. Фото можно привязать к варианту (напр. фото белого варианта).
Первое фото = главное. Сортировка перетаскиванием.

**Шаг 4 / SEO** (опционально)
```
meta title, meta description
URL slug (автогенерация из title)
```

### 4.6 Управление заказами

Список заказов с фильтрами: статус / период / поиск по номеру.

Карточка заказа:
```
#WB-2026-00142 от 23.05.2026 19:32
Покупатель: Иван Петров (+7 999 xxx xx xx)
Товары: [список с фото, названием, вариантом, кол-вом, ценой]
Итого: 7 900 ₽ + доставка 350 ₽ = 8 250 ₽
Оплата: Карта онлайн ✓ Оплачен
Доставка: СДЭК → Москва, ул. Ленина 1 кв. 5
─────────────────────────────────────────
Статус: [confirmed ▼]   [Изменить статус]
Трекинг: _________________  [Сохранить]
Примечание менеджера: ____________________
─────────────────────────────────────────
История статусов:
  new           → 23.05 19:32
  confirmed     → 23.05 19:45 (менеджер Анна)
```

### 4.7 Команда бренда

- Список участников (имя, email, роль, дата добавления)
- Кнопка "Пригласить" → email + роль → отправляем письмо со ссылкой `/brand/invite/{token}`
- Owner может убирать менеджеров

---

## 5. ЛК Клиента (Customer Dashboard)

### 5.1 Регистрация

Форма: email + пароль + имя. Подтверждение email (verification link).
Альтернативно: вход через VK (OAuth) — можно добавить позже.

### 5.2 Структура роутинга

```
/account                        — дашборд (активные заказы + рекомендации)
/account/profile                — профиль (имя, email, телефон, аватар)
/account/orders                 — история заказов
/account/orders/{number}        — детали заказа + трекинг
/account/addresses              — адреса доставки (CRUD)
/account/favorites              — избранное
/account/notifications          — настройки уведомлений (каналы)
/account/security               — смена пароля, привязка Telegram
```

### 5.3 Оформление заказа

**Корзина (/cart)**
- Список товаров (фото, название, вариант, qty spinner, удалить)
- Сводка: subtotal, доставка (считается по методу), итого
- Кнопка "Оформить заказ"
- Для незарегистрированных: предложить войти или продолжить как гость

**Важно**: товары разных брендов в одной корзине. При оформлении — разбиваем на отдельные заказы по брендам.

**Чекаут (/checkout)**

Шаг 1 / Адрес и получатель:
- Выбор из сохранённых адресов или новый
- Имя, телефон, адрес (город + улица + дом + квартира + индекс)

Шаг 2 / Доставка:
- СДЭК, Boxberry, Почта России — с расчётом стоимости и сроков
- Самовывоз (если бренд указал адрес шоурума)

Шаг 3 / Оплата:
- Карта онлайн (ЮKassa / Stripe)
- СБП (быстрые платежи)
- Наличными при получении

Шаг 4 / Подтверждение:
- Сводка заказа
- Кнопка "Подтвердить и оплатить"

**Страница успеха (/checkout/success/{orderNumber})**:
- "Заказ #WB-2026-00142 оформлен!"
- Ожидаемые сроки
- Ссылка на отслеживание
- CTA: "Продолжить покупки"

### 5.4 Трекинг заказа

На `/account/orders/{number}`:
```
Прогресс-бар: [Новый] → [Подтверждён] → [Отправлен] → [Доставлен]
Трекинг-номер: CD123456789RU [Отследить на сайте СДЭК →]
```

---

## 6. Статусы заказа

```
new          — только что создан
confirmed    — бренд подтвердил наличие
processing   — готовится к отправке
shipped      — отправлен (добавлен трекинг)
delivered    — доставлен (вручную или автоматически от трекинга)
completed    — покупатель получил, претензий нет
cancelled    — отменён (до отправки)
returned     — возврат инициирован
refunded     — возврат средств выполнен
```

Переходы для бренда: `new → confirmed → processing → shipped`
Переходы для платформы: `delivered → completed`, `* → cancelled`, `delivered → returned → refunded`

---

## 7. Каналы уведомлений

### 7.1 In-app (колокольчик)

Хранится в таблице `notification`. Badge с количеством непрочитанных.
Обновление в реальном времени через Symfony Mercure (SSE) или polling каждые 30 сек.

### 7.2 Email

Symfony Mailer. Транзакционные письма:
- **Покупатель**: подтверждение регистрации, подтверждение заказа, смена статуса, запрос отзыва
- **Бренд**: новый заказ, сообщение от покупателя, статистика за неделю

Транспорт: Brevo (ex-Sendinblue), Mailgun или SMTP.

### 7.3 Telegram

Symfony Notifier с TelegramTransport (уже установлен).

Пользователь привязывает аккаунт: в `/account/security` нажимает "Привязать Telegram" → получает ссылку `/start?token=xxx` для бота → бот сохраняет `telegramChatId` в User.

Уведомления бренду особенно важны: менеджер получает в Telegram мгновенно, как только пришёл новый заказ.

```
🛍 Новый заказ #WB-2026-00142
Покупатель: Иван Петров
Товар: Худи Basic S / Чёрный × 1
Сумма: 4 900 ₽
[Посмотреть заказ →]
```

### 7.4 Push (Web Push / PWA)

Через vapid + Symfony Notifier WebPushTransport (или OneSignal SDK).
Требует HTTPS. Пользователь разрешает push при первом входе.

### 7.5 Матрица событий и каналов

| Событие                     | In-app | Email | Telegram | Push |
|-----------------------------|:------:|:-----:|:--------:|:----:|
| Новый заказ (бренд)         | ✓      | ✓     | ✓        | ✓    |
| Статус заказа изменён (клиент) | ✓   | ✓     | опц.     | опц. |
| Заказ отправлен (клиент)    | ✓      | ✓     | опц.     | ✓    |
| Заказ доставлен (клиент)    | ✓      | ✓     | опц.     | ✓    |
| Приглашение в команду       | —      | ✓     | —        | —    |
| Остаток товара = 0 (бренд)  | ✓      | ✓     | опц.     | —    |
| Еженедельная статистика     | —      | ✓     | —        | —    |
| Подтверждение email         | —      | ✓     | —        | —    |

Пользователь управляет своими предпочтениями в настройках (NotificationSettings).

---

## 8. Настройки безопасности (security.yaml)

Добавить второй firewall для front-end пользователей:

```yaml
providers:
  app_user_provider:
    entity:
      class: App\Entity\User
      property: email

firewalls:
  brand:
    pattern: ^/brand
    lazy: true
    provider: app_user_provider
    form_login:
      login_path: brand_login
      check_path: brand_login
      default_target_path: brand_dashboard
    logout:
      path: brand_logout
      target: brand_login

  main:
    pattern: ^/
    lazy: true
    provider: app_user_provider
    form_login:
      login_path: app_login
      check_path: app_login
      default_target_path: account_dashboard
    logout:
      path: app_logout
    remember_me:
      secret: '%kernel.secret%'
      lifetime: 2592000 # 30 дней

access_control:
  - { path: ^/brand/login, roles: PUBLIC_ACCESS }
  - { path: ^/brand, roles: ROLE_BRAND_MANAGER }
  - { path: ^/account, roles: ROLE_USER }
  - { path: ^/checkout, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 9. Порядок реализации (приоритеты)

### MVP (выход на первые продажи)

1. **User entity + auth** — регистрация, вход, security.yaml (3-4 часа)
2. **BrandUser + claim бренда** — форма заявки + admin верификация (2 часа)
3. **ЛК Бренда: профиль** — редактирование бренда (уже есть через EasyAdmin, нужен публичный интерфейс) (4 часа)
4. **Product расширение + ProductVariant + ProductImage** — новые Entity + миграция (3 часа)
5. **ЛК Бренда: добавление товара** — многошаговая форма (8 часов)
6. **Страница товара** — публичная (4 часа)
7. **Cart + Checkout** — корзина, оформление (8 часов)
8. **Order + OrderItem** — создание заказа (4 часа)
9. **Email уведомления** — новый заказ + смена статуса (4 часа)
10. **ЛК Клиента** — история заказов, профиль (4 часа)

**Итого MVP: ~45 часов разработки**

### После MVP

- Telegram-уведомления
- Аналитика бренда
- Система отзывов
- Избранное / вишлист
- Расчёт доставки (СДЭК API)
- Интеграция оплаты (ЮKassa)
- Push-уведомления
- Команда бренда (инвайты)
- Возвраты

---

## 10. Новые Entity — файлы для создания

Создать в `src/Entity/`:

1. `User.php` — с `UserInterface`, `PasswordAuthenticatedUserInterface`
2. `BrandUser.php`
3. `BrandInvite.php`
4. `ProductCategory.php`
5. `ProductVariant.php`
6. `ProductImage.php` (частично есть `BrandImage` — нужен отдельный)
7. `Address.php`
8. `Cart.php`
9. `CartItem.php`
10. `Order.php`
11. `OrderItem.php`
12. `OrderStatusHistory.php`
13. `Notification.php`
14. `NotificationSettings.php`

Расширить `Product.php`: добавить title, slug, category, gender, styles, status, метатеги.
