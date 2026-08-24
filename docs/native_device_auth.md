# Native device auth для wardrobe API

Нативный клиент использует opaque credentials только для `/api/v1/wardrobe-app/*`.
PWA продолжает работать с существующей Symfony session cookie без изменений.

## Получение токенов

```http
POST /api/v1/wardrobe-app/auth/login
Content-Type: application/json

{"email":"user@example.com","password":"...","deviceId":"ios-installation-uuid"}
```

Ответ содержит короткоживущий access token (15 минут) и refresh token (30 дней). Клиент обязан
хранить их в iOS Keychain, не в `UserDefaults`, логах или аналитике. `deviceId` — случайный стабильный
идентификатор установки длиной 8–128 символов, не IDFA и не аппаратный fingerprint.

Access передаётся стандартно:

```http
Authorization: Bearer <accessToken>
```

В БД сохраняются только SHA-256 hashes access/refresh/device ID. Raw tokens возвращаются один раз,
все ответы auth API имеют `Cache-Control: no-store, private`. Login ограничен пятью попытками за
15 минут на пару IP+email; ответ для неизвестного email и неверного пароля одинаковый.

## Rotation и отзыв

```http
POST /api/v1/wardrobe-app/auth/refresh
{"refreshToken":"..."}

POST /api/v1/wardrobe-app/auth/revoke
Authorization: Bearer <accessToken>

POST /api/v1/wardrobe-app/auth/revoke-all
Authorization: Bearer <accessToken>
```

Каждый refresh одноразовый и атомарно заменяет access и refresh. Повторное предъявление уже
использованного refresh считается reuse: вся device session отзывается, включая новый access.
`revoke` отзывает текущее устройство, `revoke-all` — все устройства пользователя. Оба endpoint
требуют native Bearer-контекст и не принимают одну лишь browser cookie, поэтому не создают CSRF-path.

Повторный login с тем же `deviceId` отзывает прежнюю сессию установки. Заблокированные пользователи
не могут получить токены. Family authorization после аутентификации остаётся в `FamilyService`, как
для PWA: детский токен не открывает гардероб родителя, sibling или постороннего пользователя.

## Production smoke

После миграции выполнить login тестового аккаунта, затем:

1. вызвать `/bootstrap` с access — ожидается 200 и `no-store`;
2. refresh — новые access и refresh отличаются от старых;
3. старый access — 401;
4. повторить старый refresh — 401, новый access после reuse тоже 401;
5. убедиться SQL-проверкой, что колонки содержат 64-символьные hashes, а raw tokens нигде не записаны.

Не помещать токены в query string. OIDC/social sign-in и фоновые push credentials не входят в этот
атомарный контур и должны добавляться отдельным threat-model/PR.
