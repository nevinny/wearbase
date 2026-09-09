# Подтверждение владения брендом — методы верификации

**Создан:** 2026-05-31 · **Ветка:** task-20

Расширение claim-флоу: вместо «только совпадение домена email + ручная проверка»
добавлены self-serve методы. Старт — **код на email бренда** и **VK-админ**.

## Архитектура

| Слой | Файл |
|---|---|
| Сущность | `src/Entity/BrandClaim.php` (+ поля method, verification_code/token, code_*, verified_via) |
| Миграция | `migrations/Version20260531_brand_claim_verification.php` |
| Логика | `src/Service/BrandClaimService.php` |
| VK OAuth | `src/Service/VkVerifier.php` |
| Контроллер | `src/Controller/BrandClaimController.php` |
| Шаблоны | `templates/brand_claim/form.html.twig`, `templates/emails/brand_claim_code.html.twig` |
| Конфиг | `config/services.yaml`, `.env` (BRAND_CLAIM_AUTOGRANT_*, VK_APP_ID/SECRET) |

## Доверие и авто-выдача

- `BrandClaimService::availableMethods(Brand)` — какие методы предложить. **VK-группа определяется по host URL ссылки** (`vk.com/<ref>`), а не по `brand_link.link_type` (он часто пуст после обогащения).
- Сильные методы (`email_code`, `vk_admin`) → авто-выдача доступа (`grantOwnership`), как «Я владелец» в Яндекс/Google. Управляется флагами `BRAND_CLAIM_AUTOGRANT_EMAIL/VK` (по умолчанию on) — отключаются без правки кода.
- Слабые методы (документы, маркетплейс) → ручная проверка админом.
- **Гард:** если у бренда уже есть владелец-другой пользователь — авто-выдача НЕ срабатывает, заявка уходит в ручную проверку (`brandHasOtherOwner`).
- `grantOwnership` идемпотентен и единый для self-serve и админ-апрува (устранил расхождение двух approve-веток: теперь обе выдают `ROLE_BRAND_MANAGER`+`ROLE_BRAND_OWNER` + free-trial).
- `verified_via` фиксирует, чем подтверждено (`email_code` / `vk_admin` / `admin`).

## Метод: код на email бренда — ГОТОВО

Поток: форма → «Отправить код» (`POST /brand-claim/{id}/email/send`) → 6-значный код на `brand.email` (шаблон `brand_claim_code`) → ввод (`POST /brand-claim/{id}/email/verify`) → авто-выдача.

Защита: TTL 15 мин, cooldown 60 сек между отправками, лимит 5 отправок, лимит 5 попыток ввода, `hash_equals`. CSRF на обеих формах (`brand_claim_email`).

Статус: реализовано, покрыто юнит-тестами (`tests/Service/BrandClaimServiceTest.php`, 13 кейсов: детект, гейтинг, флаги, гард, sent/cooldown/limit/mismatch/expired/too_many/no_code). Полный клик-тест через браузер требует логина (функц. тесты сейчас блокируются предсущ. проблемой тест-среды `app_currency`).

## Метод: VK-админ — CODE-COMPLETE, НЕ ПРОВЕРЕНО ВЖИВУЮ

VK ID, OAuth 2.1 + PKCE:
`GET /brand-claim/{id}/vk/start` → `id.vk.com/authorize` (code_challenge S256, state) →
`GET /brand-claim/vk/callback` → обмен code на token (`id.vk.com/oauth2/auth`, device_id+code_verifier) →
`groups.get?filter=admin` → сверка с id группы бренда (`groups.getById`) → авто-выдача.

PKCE `code_verifier` + `state` (анти-CSRF) хранятся в сессии до callback, state сверяется.

### Что нужно для запуска (делает владелец проекта)
1. Зарегистрировать VK ID приложение, получить `VK_APP_ID` / `VK_APP_SECRET` → в `.env.local`.
2. Зарегистрировать redirect_uri = абсолютный URL роута `brand_claim_vk_callback`.

### Открытые точки — сверить с боевым VK (пометки в `VkVerifier.php`)
- Точное имя **scope** для управления группами (сейчас `groups`).
- Параметр **device_id** приходит на redirect вместе с `code` — обязателен в обмене токена.
- Сигнатура **groups.getById** (param `group_id`) и форма ответа (`response.groups[]` vs `response[]`).
- Личная VK-ссылка (профиль, не группа) → `groups.getById` не резолвится → метод недоступен для такого бренда (ожидаемо).

Без `VK_APP_ID/SECRET` метод деградирует gracefully (карточка не показывается / «временно недоступно»).

## Разведка рынка 2026-07-30

Актуальные способы (что делают Trustpilot/GBP/Яндекс Бизнес/WB/Ozon), цены телефонной верификации в
РФ и принятая лестница L0/L1/L2 — **[brand_verification_options.md](brand_verification_options.md)**.
Оттуда же два вывода, влияющих на этот флоу: SMS проигрывает голосовому коду и Telegram Gateway в
3–13 раз (→ бэклог), а обязательный backlink за листинг — риск link-spam для нас самих.

⚠️ **Ограничение `email_code` для самозарегистрированных брендов.** Код уходит на `brand.email` из
нашей БД. У каталожного бренда это адрес, добытый обогащением с его сайта — метод корректен. У
самрега этот адрес ввёл сам заявитель, поэтому доказывает только владение своей же почтой. Для
самрега адрес обязан браться свежим скрейпом официального сайта — см.
[brand_self_service.md](brand_self_service.md) §3 (разделение `identity_match` / `control_proof`).

## Следующие методы (не реализованы)
`document` (товарный знак/ИНН/ОГРН, Vich-загрузка), `marketplace` (кабинет WB/Ozon/Lamoda), Telegram-бот-админ, SMS на `brand.phone`. Все — ветка ручной проверки или отдельные провайдеры.

## Тесты
- `tests/Service/BrandClaimServiceTest.php` — 13 кейсов (детект VK по URL, гейтинг email, флаги авто-выдачи, гард «уже занят», код: sent/cooldown/limit/mismatch/expired/too_many/no_code).
- `tests/Service/VkVerifierTest.php` — 3 кейса (isConfigured, code_verifier, authorize URL + PKCE S256).
- `tests/Service/BrandClaimGrantIntegrationTest.php` — реальное выполнение `grantOwnership` против БД: BrandUser(owner) + роли + free-trial подписка + статус approved. Запуск: `MAILER_DSN=null://null php bin/phpunit tests/Service/`.
- `NotificationDispatcherTest` адаптирован под новый контракт диспетчера (INC-18: больше не `flush`, коммитит вызывающий). Мой код учитывает это (dispatch до финального flush / flush после dispatch).

Весь `tests/Service/` — 24/24 зелёные. Функциональные web-тесты (`tests/Controller/`) по-прежнему красные из-за предсущ. проблемы `app_currency` в тест-среде (не связано).

---

## Аудит воронки 2026-08-28: happy path не существовал

Повод — TG-пинг «📩 Заявка на бренд «МАКСИМ МАКСАКОВ» от zaripovs353@gmail.com» (27.08, 21:54).

**Что показали данные прода.** За всю историю в `brand_claim` — две строки (#10 июнь, #11 август),
у обеих `method = NULL` и `comment = NULL`. То есть **ни одной реально поданной заявки не было**:
`BrandClaimController::new()` (GET) персистил claim при каждом открытии формы, а
`AdminTelegramSubscriber` пинговал админа на вставку — админ получал «заявку» на **просмотр
страницы**. Заявку #10 в июне отклонили вручную как настоящую. Регистрация 21:54:52 → строка
заявки 21:54:54 → отвал: человек ушёл со страницы за секунды.

**Почему ушёл.** Для бренда 109 оба self-serve метода были мертвы:
- `email_code` — у бренда пустой `brand.email` (по каталогу: **854 email на 2777 active брендов**, метод недоступен ~69% карточек);
- `vk_admin` — кнопка показывалась (ссылка `vk.com/maximmaksakov1999` есть; по каталогу 686 брендов с VK), но `availableMethods()` не сверялся с `VkVerifier::isConfigured()`, а `VK_APP_ID/VK_APP_SECRET` на проде **пустые** → клик давал flash «временно недоступно».

Оставался только `manual` (комментарий + ручная проверка) — и он не CTA, а форма внизу страницы.

**Исправлено в этой ветке (`fix/brand-claim-happy-path`).**
1. `availableMethods()` не предлагает `vk_admin`, пока VK-приложение не сконфигурировано — тупиковой кнопки больше нет.
2. `new()` (GET) **не создаёт** строку заявки: рендерится существующая pending или транзиентная. Персист — только в POST-роутах (`getOrCreateClaim`).
3. `AdminTelegramSubscriber` больше не обрабатывает `BrandClaim` (пинг на вставку = «зашёл на страницу»; о реальной подаче уведомляет `BrandClaimController::notifyAdmin()`, там свой TG-пинг «📝 Новая заявка»).
4. При авто-выдаче (`isAutoGrant`) админу уходит TG «✅ Бренд забрали» — раньше успешный self-serve захват проходил для админа незаметно.

**Открытые дыры (не закрыты кодом, требуют решения).**
- **Пустой `brand.email` у 69% каталога** — главный блокер self-serve. Для 109 контакт есть на самом сайте (`maximmaksakov.ru`, страница оферты → `maximmaksakovww@gmail.com`), но `app:brand:enrich-contacts` идёт через локальную LLM и падает, когда GPU-сервер недоступен. Нужен дешёвый безмодельный фолбэк: скрейп `/contacts|/pages/*oferta*` + regex по `mailto:`/email — без LLM.
- **VK-приложение не заведено** (`VK_APP_ID/SECRET` пустые). С ним и `BRAND_CLAIM_AUTOGRANT_VK=1` у брендов с VK-ссылкой появляется мгновенный self-serve путь.
- **Заявителю не пишут писем.** После регистрации уходит только `verify_email`; ни «как забрать карточку», ни статуса заявки. In-app `Notification` он не увидит, если не зайдёт в кабинет.
- **Нет дефолта по таймауту** у `pending`-заявки (см. `autonomous-catalog-moderation`): висит вечно.
- `notifyAdmin()` шлёт три канала (in-app заявителю, письмо на `ADMIN_EMAIL`, TG) — дублирование стоит свести.
