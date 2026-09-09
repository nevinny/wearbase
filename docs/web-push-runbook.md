# Web Push: production runbook

Web Push is an optional, explicit opt-in channel. In-app notifications remain primary. A subscription always belongs to the currently authenticated account; API requests never accept a family member or recipient id.

## VAPID setup

Generate the key pair once in a protected administrative environment:

```bash
php -r "require 'vendor/autoload.php'; print_r(Minishlink\\WebPush\\VAPID::createVapidKeys());"
```

Store the result only in the production secret store:

```dotenv
WEB_PUSH_PUBLIC_KEY=<publicKey>
WEB_PUSH_PRIVATE_KEY=<privateKey>
WEB_PUSH_SUBJECT=mailto:admin@wearbase.ru
```

The private key must not be placed in Git, frontend markup, logs, error trackers, or support tickets. Deploy, run `php bin/console doctrine:migrations:migrate --no-interaction`, then clear the production cache. An empty key pair safely disables delivery and hides the opt-in button.

## User flow and privacy

On `/account/notifications/settings`, the user enables Push for individual event types and separately presses “Включить push” on each device. Browser permission is requested only after that gesture. The server stores the push endpoint and browser keys because the Web Push protocol needs them for delivery; treat database backups as secrets. Logs contain only a generic failure and provider HTTP status, never endpoint or keys.

The payload contains the recipient's notification title/body and either a validated `/account/purchases/{id}` URL or `/account/notifications`. The service worker independently enforces same-origin `/account/*` navigation. Parent, child, and adult accounts receive only notifications addressed to their own `User` record.

## Operations

- Run `php bin/console app:notifications:cleanup-web-push` daily. Provider responses 404/410 revoke subscriptions; revoked rows are deleted after 30 days.
- Check that `service-worker.js` is served with JavaScript MIME and scope `/account/`.
- Test Safari/iOS only from an installed Home Screen PWA; iOS prompts do not work in a normal Safari tab.
- Rotate VAPID keys only during an incident: existing subscriptions become unusable and users must opt in again.
- Run `php bin/console app:notification:deliver-outbox --limit=100` from the existing scheduler/worker. Push uses the same lease, retry backoff, maximum attempts and dedupe guarantees as email and Telegram.

## Delivery boundary

`NotificationDispatcher` persists a `push` outbox row in the same transaction as the domain change and in-app notification. It performs no network I/O. Only `app:notification:deliver-outbox` claims a committed row and calls `WebPushPublisherInterface`; the worker checks the user's current per-event preference before every attempt. The outbox payload contains no endpoint or browser key, and the provider resolves subscriptions only for the row's recipient.
