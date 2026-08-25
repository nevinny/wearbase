# External notification outbox

`NotificationDispatcher::dispatch()` persists in-app notifications and enabled email/Telegram deliveries in the same domain transaction. It performs no network I/O. Email HTML and Telegram HTML text are snapshotted at enqueue time; the latter is escaped before persistence.

`app:notification:deliver-outbox` claims due rows and sends them after commit. Failed deliveries are isolated and retried with exponential backoff up to five attempts. Preferences are checked both when queued and immediately before delivery, so an opt-out made while a message waits is respected. The production scheduler runs the command every minute. Use `--dry-run` to count pending rows without claiming them.

Web Push is deliberately outside this outbox.
