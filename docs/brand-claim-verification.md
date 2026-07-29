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
