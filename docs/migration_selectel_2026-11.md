# Переезд с reg.ru на Selectel VDS (ноябрь 2026) — ранбук

**Повод.** 08–09.11.2026 хостинг reg.ru был приостановлен: истёк срок услуги. Вместе с сайтами
заблокировались все базы MySQL на аккаунте. Владелец продлил хостинг 09.11 и решил переезжать на
Selectel. Порядок: 1) wearbase, 2) minerdash, 3) остальные. Выбор Selectel обоснован в [hosting-choice.md](hosting-choice.md).

**Целевой сервер:** Selectel VDS `wearbase-vds`, 161.104.35.238, Ubuntu 24.04, PHP 8.4, MySQL 8.4,
nginx (SNI-stream на :443 → 127.0.0.1:8443 с proxy_protocol). Сервер общий: на нём уже живут castbase,
drugs, roombase, pcpricebase, datacenter1. Правила: `nginx -t` перед каждым reload, общие сервисы не
рестартить, чужое не трогать. SSH-алиасы: `selectel` (deploy) и `selectel-root`. Скилл: `selectel`.

## Статус на 09.11.2026 (сделано без владельца)

| Проект | Каталог на Selectel | БД | Проверка по `--resolve` | DNS-зона в Selectel | Осталось |
|---|---|---|---|---|---|
| **wearbase.ru** | `/var/www/wearbase` (user `wearbase`, пул/сокет `wearbase`) | `wearbase` (150 таблиц, дамп 09.11 09:39) | 37/37 URL совпали с reg.ru | создана, запись для сайта → .238 | сертификат, финальная синхронизация, NS/A, секреты CI |
| **minerdash.ru** | `/var/www/minerdash` | `minerdash` (58 таблиц) | 22/22, кроме `gateway.php`: он сломан и на reg.ru (фатальная ошибка «класс не найден», reg.ru отдаёт её с кодом 200) | создана | то же |
| kupon-master.ru | `/var/www/kupon` | `kupon` (13) | 20/20 | создана | то же |
| ii-catalog.ru | `/var/www/iicatalog` | `iicatalog` (14) | 19/19 | создана | то же |
| forgetborders.club | `/var/www/fborders` | `fborders` (36) | 8/8 | **DNS у GoDaddy** — правит владелец | A-запись у GoDaddy; ⚠️ домен истекает **27.12.2026** |
| ugp.wearbase.ru | `/var/www/ugp` | `ugp` (4) | 10/10 | в зоне wearbase → .238 | едет вместе с NS wearbase |
| mos.wearbase.ru | `/var/www/mos` | — | 3/3 | в зоне wearbase → .238 | едет вместе с NS wearbase |

На reg.ru, но не переносится: `~/www` (пустой), wearbase123.ru (нет DNS), tdst.ru (живёт на Timeweb).

**Архив reg.ru** на Mac: `~/seo-archives/regru-backup-20261109/`. Там `env/*/.env.local` всех проектов,
`dumps/*.sql.gz` (дампы всех 6 БД на 09.11 09:39) и `home/` (полная копия файлов аккаунта).

### Что сделано и где лежит

- **Пулы PHP-FPM:** `/etc/php/8.4/fpm/pool.d/<name>.conf`. Лимиты как в `php-bin/<домен>/php.ini` reg.ru
  (memory 1024M, upload/post 256M, max_execution 300, max_input_vars 100000, TZ Europe/Moscow). Также
  `open_basedir` на каталог проекта и `session.save_path` внутрь `var/sessions`.
- **vhost-ы:** `/etc/nginx/sites-available/<домен>`, пока **только HTTP** (этап 1).
  - wearbase: правила перенесены из прод-`.htaccess`: `www` → apex (301), закрыт `/images/wardrobe*`,
    `/demo/` отдаётся статикой, `apple-app-site-association` идёт как JSON.
  - Остальные: существующие `.php` исполняются, как у Apache на reg.ru (gateway.php, dl/*.php и т.п.).
- **Env.**
  - wearbase: `/var/www/wearbase/.env.local` — прод-env с заменой `DATABASE_*`, `serverVersion=8.4` и
    `TELEGRAM_PROXY`. Копия лежит в `/root/wearbase.env.local`.
  - minerdash: переписан `.env.local.php` (именно он главный, `.env.local` игнорируется). Оригинал —
    `/root/minerdash.env.local.php.regru`.
  - Малые проекты: исходный `.env.local` сохранён в `/root/<name>.env.local.regru`.
  - Пароли БД: `/root/.<name>-db-pass` (0600).
- **Telegram с Selectel.** С RU-IP `api.telegram.org` не открывается (таймаут). Туннель
  `wearbase-egress.service` — `ssh -D 127.0.0.1:1082` на VPS aeza (`sweden`, 138.124.29.146), пользователь
  `wearbase-egress`. Ключ ограничен `permitopen="api.telegram.org:443"`. В коде — scoped-клиент
  `telegram.client` с `proxy: %env(TELEGRAM_PROXY)%` (PR #311). На проде
  `TELEGRAM_PROXY=socks5h://127.0.0.1:1082`. Проверено вызовом `getMe` → `wearbase_bot`.
  Туннель castbase (`castbase-egress@aeza-1`, :1081) пускает только к Instagram, для TG он не подходит.
  minerdash ходит в TG через свой PHP-прокси `forgetborders.com/tg.php`, туннель ему не нужен.
- **Проверено с Selectel:** SMTP Rusender :465, Gmail :587, cbr.ru, forgetborders-relay, API пулов
  minerdash (k1pool, trongrid, whattomine, whalepool, viabtc).
- **CI-деплой.** Ключ `~/.ssh/wearbase_ci_deploy_selectel` (и `.pub`) уже в
  `/home/wearbase/.ssh/authorized_keys`, вход проверен. Known_hosts —
  `~/.ssh/wearbase_ci_known_hosts_selectel`. Секреты GitHub пока смотрят на reg.ru.
- **CI** чинили попутно (PR #312): `ubuntu-latest` уехал на 26.04, где Playwright 1.60 не ставит Chromium.
  Job `pwa` закреплён на ubuntu-24.04, иначе deploy (needs: pwa) не запускался вообще.
- **IPv6 на VDS нет**, поэтому AAAA-записи в новые зоны не переносились. Иначе IPv6-клиенты остались бы на reg.ru.
- **DKIM Rusender** для `mail.wearbase.ru`: селектор `mdmdmail` (найден в заголовках писем). Запись
  перенесена в зону Selectel. Проверить её `dig` получится только после делегирования: до него Selectel
  на запросы к зоне отвечает REFUSED.

### Скрипты финальной синхронизации (на Selectel)

Все запускаются **с Mac** с пробросом агента: reg.ru пускает по ключу Mac.

| Проект | Команда | Что делает |
|---|---|---|
| wearbase | `ssh -A selectel-root /root/wearbase-cutover/final-sync.sh` | комментирует 2 крона wearbase на reg.ru (`#MOVED-TO-SELECTEL`) → свежий дамп → пересоздаёт БД и импортирует → `cutover_ids.env` (время и max id листовых таблиц) → дельта `images`/`var/uploads`/`config/secrets` → cache:clear → `/etc/cron.d/wearbase` → smoke |
| minerdash | `ssh -A selectel-root /root/minerdash-cutover/final-sync.sh` | то же: 3 крона, креды из `.env.local.php`, `/etc/cron.d/minerdash` |
| kupon, iicatalog, fborders, ugp, mos | `ssh -A selectel-root /root/regru-migration/final-sync-small.sh <name> <папка-на-reg.ru>` | кроны проекта на reg.ru → дамп/импорт (если есть БД) → дельта загрузок → кроны из `/root/regru-migration/cron.d/<name>` |

Регулярки отключения кронов проверены всухую на реальном crontab: каждая цепляет ровно строки своего
проекта (`forward-hello.php` и чужие не трогает). Скрипт для малых проектов целиком прогнан на ugp.

## Переключение — два захода (с владельцем)

Почему в два захода: у A-записей на reg.ru TTL 86400, а делегирование .ru кешируется ещё дольше.
Если сменить всё за один раз, сутки часть посетителей пишет в старую базу (split-brain). Снизив TTL
заранее, это окно сжимаем до минут, и склеивать базы не придётся.

### Заход 1 (≈10 мин, панель reg.ru; GoDaddy — для forgetborders.club)

1. Для wearbase.ru, minerdash.ru, kupon-master.ru, ii-catalog.ru поставить **TTL 300** у A-записей
   (`@` и `www`), если панель это позволяет.
2. Добавить TXT `_acme-challenge` (и `_acme-challenge.www`) — значения я сгенерирую в момент захода.
   По ним certbot на Selectel выпускает сертификаты **до** переключения DNS. Нужен DNS-01: reg.ru
   перехватывает `/.well-known/acme-challenge/` своим nginx (проверено, отдаёт 404), так что HTTP-01
   через старый хостинг невозможен.
3. Я выпускаю сертификаты. Хук certbot ждёт появления TXT на ns1/ns2.reg.ru. Затем ставлю
   443-блоки vhost-ов: пара `listen 127.0.0.1:8443 ssl proxy_protocol;` и `listen [::]:443 ssl;`, как у castbase.

Альтернатива заходам: дать API-доступ reg.ru (логин, API-пароль, разрешённый IP). Тогда TTL, TXT и NS
я меняю сам.

### Заход 2 (≥24 ч после захода 1, ≈10 мин)

1. Я запускаю final-sync по проектам. Каждый выключает крон проекта на reg.ru и включает на Selectel.
2. Владелец в панели reg.ru: A-записи `@`/`www` → **161.104.35.238** и **сразу** NS →
   `a.ns.selectel.ru b.ns.selectel.ru c.ns.selectel.ru d.ns.selectel.ru` (wearbase, minerdash, kupon,
   ii-catalog). Менять A на reg.ru тоже нужно: резолверы со старым делегированием ещё какое-то время
   спрашивают reg.ru. forgetborders.club — у GoDaddy: только A → .238.
3. Я переключаю секреты CI (`gh secret set`):
   `DEPLOY_HOST=161.104.35.238`, `DEPLOY_USER=wearbase`, `DEPLOY_PORT=22`, `DEPLOY_PATH=/var/www/wearbase`,
   `DEPLOY_SSH_KEY` < `~/.ssh/wearbase_ci_deploy_selectel`,
   `DEPLOY_KNOWN_HOSTS` < `~/.ssh/wearbase_ci_known_hosts_selectel`.
4. Проверки: `selectel dns check wearbase.ru`, smoke `/ru/ /ru/brands/ /sitemap.xml`, письмо через
   Rusender, алерт в TG, `app:cron:run-scheduled` пишет в `var/log/cron.log`. Через час на reg.ru
   выполнить `SELECT COUNT(*) … WHERE created_at > cutover_ts` по `client`, `wardrobe_outfit`,
   `brand_datapoint_vote`, чтобы убедиться, что после переключения туда ничего не записалось.

### После переключения (хвосты)

- `docs/production.md`, CLAUDE.md (карта хостов), скиллы `deploy`/`status`/`add-brand`/`brand-audit`,
  `.claude/agents/backend-developer.md`: заменить `regru` на Selectel.
- Mac `.env.local`: `SOCIAL_CLICK_LOG_HOST=selectel`,
  `SOCIAL_CLICK_LOG_GLOB=/var/log/nginx/wearbase.access.log*`. Пользователь `deploy` уже в группе `adm`
  и читает логи nginx. Формат логов на Selectel — стандартный combined, такой же, как у reg.ru.
- `ScheduledCommand` подписывает env `prod` как «Прод (regru)» — косметика.
- Ротация `var/log/*.log` проектов: на reg.ru её делал хостинг, здесь надо добавить logrotate.
- Бэкапы БД на Selectel. Сейчас их нет; в hosting-choice план — mysqldump + restic в Selectel S3.

### Что остаётся на reg.ru и решается отдельно

- **Почта.** MX `mail.wearbase.ru` → `mx*.hosting.reg.ru` (ящик `hello@` и крон `forward-hello.php`), MX
  kupon-master.ru/ii-catalog.ru — туда же. Транзакционные письма идут через Rusender и от reg.ru не
  зависят. Но пока reg.ru не отключён, входящие на `hello@` живут там. До отказа от хостинга нужно
  перенести почту, например в Яндекс 360 или Mail.ru для бизнеса, или решить, что ящик не нужен.
- Сам хостинг reg.ru можно отключать после заходов 1–2 и решения по почте.
