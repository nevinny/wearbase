# tg.php — fallback-реле Telegram (заграничный хост)

Тупой буфер между Telegram и Mac-советником: приём webhook в очередь + прокси отправки.
Вся логика на Mac; хост только даёт публичный URL и чистый доступ к `api.telegram.org`.

## Установка

1. Залей `tg.php` и `.htaccess` в docroot хоста (напр. `https://ХОСТ/tg.php`).
2. В `tg.php` заполни константы:
   - `BOT_TOKEN` — токен @wearbase_bot (нужен для `?send`).
   - `RELAY_TOKEN` — длинный секрет: `openssl rand -hex 32`.
   - `WEBHOOK_SECRET` — ещё один секрет (для setWebhook secret_token).
3. Проверь, что есть `pdo_sqlite` и `curl` (`php -m | grep -E 'sqlite|curl'`).
4. `DB_PATH` — по возможности путь ВНЕ docroot; иначе `.htaccess` рядом закрывает `.sqlite`.

## Эндпоинты

| Запрос | Кто зовёт | Что делает |
|---|---|---|
| `POST /tg.php` (тело Telegram) | Telegram (webhook) | кладёт апдейт в очередь; проверяет `X-Telegram-Bot-Api-Secret-Token` |
| `GET /tg.php?action=pull&token=…&limit=50` | Mac-поллер | отдаёт невыданные апдейты, помечает выданными |
| `POST /tg.php?action=send&token=…` (chat_id,text[,parse_mode,reply_markup,reply_to_message_id]) | Mac (fallback) | форвардит в Bot API sendMessage |
| `GET /tg.php?action=health&token=…` | мониторинг | глубина очереди |

## Активация (переключить бот на этот хост — режим failover)

```bash
TOKEN=<bot_token>; SECRET=<WEBHOOK_SECRET>; HOST=https://ХОСТ/tg.php
curl -s "https://api.telegram.org/bot$TOKEN/setWebhook" \
  --data-urlencode "url=$HOST" --data-urlencode "secret_token=$SECRET"
# вернуться на прод: setWebhook url=https://wearbase.ru/telegram/webhook (без secret_token)
```

## Проверка

```bash
# health
curl -s "https://ХОСТ/tg.php?action=health&token=$RELAY_TOKEN"
# отправка через прокси
curl -s "https://ХОСТ/tg.php?action=send&token=$RELAY_TOKEN" \
  --data-urlencode chat_id=140045444 --data-urlencode text="тест реле"
# забрать очередь
curl -s "https://ХОСТ/tg.php?action=pull&token=$RELAY_TOKEN"
```

Дальше на Mac — поллер `app:advisor:ask`-через-очередь + (опц.) fallback отправки через `?send`
(это следующий шаг, делается на стороне wearbase).
