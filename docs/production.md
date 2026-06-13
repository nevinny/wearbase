# Прод-окружение WEARBASE (reg.ru)

Снимок на 2026-06-12. Здесь — всё, что иначе пришлось бы выяснять по ssh.

## Сервер

| Что | Значение |
|---|---|
| SSH | алиас `regru` (настроен в `~/.ssh/config` на Mac) |
| Путь проекта | `/var/www/u3042786/data/wearbase.ru` |
| PHP | 8.2.31 CLI |
| Веб-корень | `public_html/` (как и локально) |
| БД | MySQL на хостинге, креды в прод `.env.local` |

## Деплой (канонический порядок)

```bash
# 1. Полный rsync из корня проекта (НЕ отдельные файлы — см. «Грабли деплоя» в tasktracker)
rsync -az --exclude .git --exclude var --exclude .env.local --exclude config/secrets \
  --exclude node_modules --exclude _seo --exclude _sql --exclude .opencode \
  --exclude .superpowers --exclude .DS_Store --exclude playwright-report \
  --exclude test-results ./ regru:wearbase.ru/

# 2. На сервере
ssh regru 'cd wearbase.ru \
  && php bin/console doctrine:migrations:migrate --no-interaction \
  && php bin/console cache:clear --no-debug'

# 3. Если добавлены НОВЫЕ console-команды и их надо запустить в этом же деплое:
#    cache:clear ОБЯЗАН идти ДО их запуска (no-debug контейнер кэширует список команд).

# 4. Если добавлены новые статьи блога (манифест в PublishBlogDraftsCommand + HTML в _docs/blog-drafts/):
ssh regru 'cd wearbase.ru && php bin/console app:blog:publish-drafts'

# 5. Смоук
for u in /ru/ /ru/blog /ru/cities /cart /sitemap.xml; do \
  curl -s -o /dev/null -w "$u %{http_code}\n" https://wearbase.ru$u; done
```

## Карта env (что где лежит; значения — только на сервере/в .env.local)

| Переменная | Локально (Mac) | Прод | Примечание |
|---|---|---|---|
| `MAILER_DSN` | `null://null` — письма НЕ уходят | `smtp://hello@mail.wearbase.ru@smtp.rusender.ru:465` | Rusender. ⚠️ см. «Известные проблемы» |
| `TURNSTILE_KEY/SECRET` | в `.env.local` тестовые Cloudflare (3x/1x, always-pass) | боевые (0x4AAA…) | в `.env` — те же тестовые дефолты |
| `TELEGRAM_BOT_TOKEN` | `.env.local` | прод `.env.local` | |
| `ADMIN_TELEGRAM_CHAT_ID` | `.env` | `.env` | админ-дайджесты |
| `GSC_CREDENTIALS_PATH` | `config/secrets/gsc-sa.json` | пусто (GSC гоняем с Mac) | `app:gsc:sync`, `app:report:daily` |
| `YOOKASSA_*` | пусто/тест | прод `.env.local` | |
| LLM/RAG (`LOCAL_LLM_URL` и т.д.) | 192.168.2.43 | не используется на проде | конвейер живёт на Mac/LLM-сервере, прод получает готовый контент через agent-API |

## Email (Rusender)

- Отправитель: `hello@mail.wearbase.ru`, SMTP `smtp.rusender.ru:465` (SSL, не STARTTLS).
- Проверка: `ssh regru 'cd wearbase.ru && php bin/console mailer:test nevinny@gmail.com'`.
- **Устройство кабинета Rusender («Транзакционные отправки»): две раздельные сущности.**
  - Вкладка «Ключ» — API-ключ (у нас `wearbase`, ID 4487) — для отправки через их HTTP API.
  - Вкладка «SMTP» — SMTP-подключения со СВОИМ логином/паролем. **API-ключ ≠ SMTP-пароль.**
  - Ошибка `450 SMTP connection unavailable` при авторизации = в кабинете нет живого SMTP-подключения под эти креды (это текст Rusender про их сущность, а не про сеть). Фикс: вкладка SMTP → создать подключение → новый логин/пароль → обновить `MAILER_DSN` в прод `.env.local` → `mailer:test`.
- **⚠️ Лимит тарифа: 100 писем/период** (на 2026-06-12 осталось 86, действует до 05.07.2026). Заказ = 2+ письма (бренд + покупатель), плюс verify-email и newsletter double opt-in. При реальном трафике квота кончится за дни — поднять тариф или ограничить email-канал критичными событиями.
- Ошибки отправки видны в `var/log/prod.log` (`Email notification failed`, с 2026-06-12).
- **Бесплатный обход SMTP: кастомный транспорт `rusender+api://`** (`src/Mailer/RusenderApiTransport.php`, фабрика зарегистрирована в services.yaml). Отправляет через HTTP API тем же API-ключом из вкладки «Ключ» (активен на бесплатном тарифе, SMTP-активация не нужна). DSN: `MAILER_DSN=rusender+api://<API_KEY>@default?key_id=4487`. Один получатель = один запрос к API; ошибки API кидаются как HttpTransportException и ловятся логированием EmailNotifier.

## Turnstile (Cloudflare captcha)

- Стоит на форме регистрации (`RegistrationFormType`, бандл pixelopen/cloudflare-turnstile-bundle).
- **Локально в `.env.local` тестовые ключи Cloudflare (3x…/1x… — always pass), боевые (0x4AAA…) живут ТОЛЬКО на проде** (с 2026-06-12). Headless e2e может проходить регистрацию; готовый пользователь `cart-test@example.com / Test12345!` (dev-БД, email verified) остаётся для спеков, которым регистрация не нужна.

## Уведомления: как проверить цепочку

1. In-app: лента в ЛК бренда `/brand/notifications` (и `/account/notifications` у покупателя).
2. Email: `mailer:test` (выше) + лог `Email notification failed` в prod.log.
3. Telegram: требует привязки `telegram_chat_id` у пользователя (через `/telegram/link`); без привязки канал молча пропускается. Ошибки API теперь в логе (`Telegram notification failed/rejected`).
4. Дефолты без сохранённых настроек: email + in-app включены, telegram выключен (NotificationDispatcher).

## GSC (Search Console)

- Креды: `config/secrets/gsc-sa.json` (service account) + `GSC_SITE_URL=sc-domain:wearbase.ru` — только на Mac.
- `php bin/console app:gsc:sync` — Search Analytics + покрытие индекса → таблицы `gsc_page_stats` / `gsc_index_status` (локальная БД).
- `php bin/console app:report:daily` — дайджест (публикации + GSC) в Telegram, запускать с Mac.
- ⚠️ 2026-06-12: дубль-хост `www.wearbase.ru` закрыт 301 www→apex (`public_html/.htaccess`, деплой 2026-06-12) + canonical/hreflang/sitemap от `SITE_BASE_URL`.
- ⚠️ `gsc_index_status`/`gsc_page_stats` на проде **пусты** (GSC синкается на Mac/.43). Поэтому index-guard и drip-health в `app:brand:publish-tick`, а также приоритизация `app:seo:meta-repair` по показам — на проде fail-open/без приоритета, пока индекс-данные GSC не попадут на прод. Логика готова, ждёт данных (push в агент-API или отдельный sync-таргет). `app:seo:meta-repair` на проде всё равно полезна: дефекты по длине meta она ловит без GSC.
