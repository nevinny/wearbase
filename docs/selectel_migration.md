# Переезд regru → VDS Selectel (с 2026-10-08)

Ведёт сессия wearbase-b3. Статус: заказ сервера (Ubuntu 24.04).

## Что на regru сейчас (замер 2026-10-08)
- Аккаунт `u3042786`: wearbase.ru, minerdash.ru, forgetborders.club (LLM-relay прода → риг), mos/ugp.wearbase.ru, ii-catalog.ru, kupon-master.ru.
- PHP 8.2.31, MySQL Percona 8.0.25. `public_html/images` wearbase = 3.4 ГБ (не в git — копировать отдельно).
- Замер 2026-10-08 (БД — information_schema, приблизительно):
  - wearbase: MySQL 128 МБ, файлы 3.7 ГБ; minerdash: MySQL 975 МБ, файлы 1.3 ГБ;
  - forgetborders 2 МБ / 343 МБ; ii-catalog, kupon-master, mos/ugp ~1 МБ / ~360 МБ;
  - drugs: НЕ на regru (Postgres там нет) — только Mac, Postgres 18, 1.7 ГБ.
  - Итого ~6 ГБ файлов + ~3 ГБ БД → VDS-4 (2 vCPU/4 ГБ/50 ГБ, 650 ₽) + swap 2–4 ГБ достаточно.
- Окружение regru: PHP 8.2 (wearbase/forgetborders/kupon) + 8.4 (minerdash), Percona 8.0.25.
- Кроны regru: wearbase run-scheduled, minerdash run-scheduled + pool:collect-data */10 + reward 08:00, forgetborders consume-messages, kupon import 04:00 + rotate 03:55, forward-hello */10, HADI bot-hits 02:00.
- ugp.wearbase.ru уже сломан на regru: MySQL 2061 caching_sha2_password requires secure connection.

## Чек-лист переноса wearbase (от wearbase-1d + docs/production.md)
- ⚠️ **БЛОКЕР: с VDS Selectel Telegram недоступен** (api.telegram.org/telegram.org — таймаут, и по IP 149.154.167.220; проверено 2026-10-09; с regru — 302). Webhook-бот `/telegram/webhook` (синхронные ответы) и все TG-уведомления прода (ADMIN_TELEGRAM_CHAT_ID, health, лиды) отвалятся молча. До cutover решить: relay `tg.php` на forgetborders / прокси через риг (SOCKS) / Mac. Входящий webhook от Telegram на IP VDS тоже проверить. Остальной egress (ya.ru, google, github, rusender, forgetborders) — ок.
- `.env.local` целиком (ADMIN_TELEGRAM_CHAT_ID, PAYMENT_SECRET_KEY — без него реквизиты не расшифруются, ext-sodium).
- `public_html/images` rsync с regru.
- Кроны прода (crontab regru + docs/commands.md): deliver-outbox ежеминутно, publish-tick/модерация (flock), `~/bin/forward-hello.php` */10 — ВНЕ репо, переносить руками.
- TZ системы = Europe/Moscow (MySQL в МСК).
- nginx/php-fpm timeout > 50 с (long-poll LLM-relay стилиста).
- После cutover: TG setWebhook (`/telegram/webhook`), URL вебхука YooKassa, проверить RuSender (API, не SMTP), Turnstile-домен.
- GitHub Actions: обновить `DEPLOY_*` секреты на новый хост.
- Сверить прод с main перед cutover (прод бывает впереди: /demo/ .htaccess+robots).
- Заморозка мержей в main на время cutover — оповестить wearbase-1d, miner-dash-ed.

## miner-dash (ответ miner-dash-ed, 2026-10-08)
- Остаётся на риге: ollama, llm-worker + guard (тянет очередь с forgetborders llmq.php; при переносе llmq.php — сменить URL воркеру), rig-dashboard :8088, wildrig/SRB, watchdog, gpu-tune.
- Можно перенести: asic-exporter (:9101, stdlib python) + Prometheus. Требует VDS в tailnet с `--accept-routes` (Ангар 192.168.1.0/24 subnet router, Подвал 4via6). История Prometheus на риге с 28.09.
- Бонус: статичный IP VDS → whitelist ViaBTC (сейчас «IP not allowed» с Mac/VPN) — кроны пулов можно жить на VDS.
- minerdash.ru (regru shared, PHP 8.4, docroot public→public_html) — отдельная задача, только с решения владельца. Ключи MEXC на VDS не тащить без решения.

## VDS cameron (161.104.35.238, ru-2c, VDS-4) — решения
- Сервер общий под несколько проектов (wearbase, minerdash, …, drugs — новый, + будущие).
- Один PHP 8.4 для всех (решение пользователя 2026-10-08).
- MySQL 8.4 LTS (существующие проекты как есть) + Postgres 18 (drugs и новые). Перевод wearbase на PG — отдельная задача, не в рамках переезда (202 MySQL-миграции, 62 файла сырого SQL: ON DUPLICATE KEY ×21, INSERT IGNORE ×9).
- Сделано: TZ МСК, hostname wearbase-vds, swap 3G, ufw 22/80/443, fail2ban, unattended-upgrades, user deploy (ключ, NOPASSWD sudo). Алиасы `ssh selectel` / `selectel-root`.

### Стек (поставлен 2026-10-08)
- nginx 1.24, PHP 8.4.26-fpm (ppa:ondrej; ext: mysql, pgsql, sqlite3, intl, mbstring, xml, curl, zip, gd, bcmath, opcache, imagick, apcu, sodium), composer 2.10.
- MySQL 8.4.11 LTS (repo.mysql.com; ключ RPM-GPG-KEY-mysql-2023 на сайте ПРОСРОЧЕН — свежий брать с keyserver.ubuntu.com, B7B3B788A8D3785C, до 2027-10-23). root = auth_socket. `/etc/mysql/mysql.conf.d/zz-vds.cnf`: 127.0.0.1, buffer_pool 768M, perf_schema off, TZ +03:00, utf8mb4.
- Postgres 18.6 (PGDG), `conf.d/vds.conf`: shared_buffers 256MB, TZ Москва; слушает только localhost.
- PHP: `conf.d/99-vds.ini` (TZ, upload 32M, opcache 192M); CLI memory_limit 512M.
- nginx: default_server → 444 (неизвестные хосты); server_tokens off; body 34M.
- Схема проекта: юзер `<name>` (ssh тем же ключом), код прямо в `/var/www/<name>` (как `~/work/<name>` на Mac; home юзера — `/home/<name>`), свой fpm-пул `/run/php/<name>.sock` (ondemand, 6 детей, 256M, open_basedir, timeout 120s), vhost `/etc/nginx/sites-available/<domain>` (Symfony, fastcgi_read_timeout 120s), своя БД+юзер.
  - `vds-new-project <name> <domain> [mysql|pgsql|none] [webroot]` — креды в `/root/projects/<name>.creds`; HTTPS после DNS: `certbot --nginx -d <domain>`.
  - `vds-remove-project <name> <domain>` — снести (дропает БД!).
- Бэкап: `/usr/local/sbin/vds-backup` крон 03:30 → `/var/backups/vds/<дата>` (дампы всех MySQL/PG + /etc), хранение 7 дней. ⚠️ Бэкап на том же диске — off-site пока нет.
- drugs: `frontend/` — Next.js 15 (`next start`, pnpm) → на VDS нужен Node + systemd-юнит/pm2 и nginx-прокси на него (≈150–300 МБ RAM).

## drugs на VDS (2026-10-08)

- Схема: публичный nginx :80 (default_server, server_name pillbase.ru www.pillbase.ru 161.104.35.238) → Next.js standalone `127.0.0.1:3000` (systemd `drugs-frontend`, User=drugs, MemoryMax 512M, рантайм `/home/drugs/frontend-run`, атомарная подмена). Symfony API только внутри: `listen 127.0.0.1:8081` → fpm `drugs.sock`. Шаблон `vds-new-project` под Next+API не подходит — vhost переписан руками (скрипт не расширяли).
- ✅ 2026-10-09 переключено на домен: pillbase.ru делегирован на a–d.ns.selectel.ru (у регистратора сперва стояли ns1/ns2.nameself.com — сменить вручную в «DNS-серверы» → свои). LE через `certbot --nginx` (без email). Канон `https://pillbase.ru`; `www` и `http` → один 301 на апекс. `default` снова default_server: :80 → 444, :443 → `ssl_reject_handshake on` (по IP сайт не отдаётся). IP-блок удалён. `NEXT_PUBLIC_SITE_URL=https://pillbase.ru` + пересборка (workflow_dispatch). Бэкапы старых конфигов — `/root/*.bak`.
- БД: pg_dump с Mac → pg_restore (`--no-owner --role=drugs`), счётчики совпали (substances 976363, drug_products 2630, atc_codes 6996). В БД 1 выполненная миграция, которой нет в main (с ветки feat/atc-completion, PR #10) — ок, после мержа #10 будет no-op.
- Env на сервере пишется руками, деплой не трогает: `/var/www/drugs/.env.local`, `/var/www/drugs/frontend/.env.production.local` (`API_BASE_URL=http://127.0.0.1:8081`, `NEXT_PUBLIC_SITE_URL=http://161.104.35.238` — запекается при сборке; после домена поменять и пересобрать).
- Автодеплой: GitHub Actions `.github/workflows/deploy.yml` в nevinny/drugs (PR #11) на push в main → ssh drugs@VDS, ключ ограничен `command="/home/drugs/deploy.sh"`. Секреты DEPLOY_SSH_KEY / DEPLOY_KNOWN_HOSTS / DEPLOY_HOST. Server-side pull: read-only deploy key у юзера drugs.
- `/home/drugs/deploy.sh` (вне репо): flock, git reset origin/main, composer --no-dev, pg_dump в `/home/drugs/backups` если есть pending-миграции (хранится 3), migrate, cache:clear, pnpm build (NODE_OPTIONS 2048M), подмена рантайма, sudo restart drugs-frontend (sudoers `/etc/sudoers.d/drugs-deploy`), смоук `:3000/` и `:8081/api/v1/substances?search=aspirin&limit=1`. Лог `/home/drugs/deploy.log`. Смоук фронта = `/ru` 200 и `/` (Host smoke.test) отдаёт 30x не на localhost; при провале — автооткат на предыдущий рантайм (`frontend-run.old` → `frontend-run`, неудачная сборка остаётся в `frontend-run.failed`) и рестарт. Откатывается только фронт; PHP-код/миграции остаются на новой версии.
- Память: сборка Next — пик ~1.7 ГБ used из 3.8, без свопа.
- Риски: нет тестов в CI; branch protection недоступна (приватный репо на free-плане); (HTTP по IP — снято, домен с 2026-10-09).
- ⚠️ Next standalone за nginx: `req.url` в middleware = `http://localhost:3000/...` (Host/X-Forwarded-Host на него не влияют), поэтому `NextResponse.redirect(new URL(path, req.url))` уводил на localhost. Относительный `Location` в middleware НЕЛЬЗЯ — рантайм делает `new URL()` → 500 `ERR_INVALID_URL` (vitest этого не ловит; проверять только на собранном standalone). Правильно (drugs PR #13): абсолютный URL от `X-Forwarded-Host` → `Host` + `X-Forwarded-Proto`; nginx передаёт `Host`, `X-Forwarded-Host`, `X-Forwarded-Proto`. Временные обходы в nginx сняты.
- GitHub concurrency (`cancel-in-progress: false`) держит только ОДИН ожидающий прогон — промежуточные pending отменяются, деплоится последний main (это норма).
- История 2026-10-08: PR #11 автодеплой (зелёный) → серия #12, #10, #9, #8 → #12 (относительный Location) уронил пути без локали в 500 → хотфикс #13 (671355c), зелёный.
- Индексация: IP-блок (161.104.35.238/default) отдаёт `X-Robots-Tag: noindex, nofollow` + robots `Disallow: /`; блок `pillbase.ru www.pillbase.ru` — без запрета. Прокси общий: `/etc/nginx/snippets/drugs-proxy.conf`. При переходе на домен IP-блок удалить, 444-заглушке вернуть default_server.
- Архив access-логов (с 2026-10-08 16:51, для bot_hits): `/var/log/nginx/drugs/{pillbase.ru,ip}.access.log`, формат `main_ext` (combined + `$host $request_time`, `/etc/nginx/conf.d/10-log-formats.conf`), logrotate `/etc/logrotate.d/nginx-drugs` daily/compress/dateext, rotate 3650 (не удаляются), 640 www-data:drugs.
- Кроны drugs — crontab юзера drugs на VDS (не в репо): `30 5 * * *` `app:links:build` (flock, лог `var/log/links-build.log`). `15 * * * *` `app:seo:ingest-bot-log` (логи `pillbase.ru.access.log*` + старые `ip.access.log*`, `--verify-dns`). `45 5 * * *` `app:seo:indexnow` (только новые/изменённые URL; первая отправка 3743 URL 2026-10-09 → 202; ключ в env `INDEXNOW_KEY` обоих .env, отдаётся роутом `/indexnow.txt`).
- Frontend env на сервере: `NEXT_PUBLIC_YM_ID=113561532` (Метрика), верификацию GSC/Вебмастера — через DNS TXT в зоне Selectel.

## roombase на VDS (2026-10-10, сессия fork-wearbase-to-roombase)
- Форк движка wearbase под мебель, репо `nevinny/roombase` (private), локально `~/work/roombase`.
- `vds-new-project roombase roombase.ru mysql public_html`, затем переведён на **PostgreSQL 18 + PostGIS 3.6.4** (нужны гео-функции): `apt install --no-upgrade postgresql-18-postgis-3` (49 пакетов, PG не рестартовал), БД/роль `roombase` (без SUPERUSER/CREATEDB), `CREATE EXTENSION postgis` под postgres; миграции — `IF NOT EXISTS`. MySQL-БД/юзер удалены точечно. Креды — одна строка PG в `/root/projects/roombase.creds`.
- ⚠️ Бэкап `pg_dump -Fc` с PostGIS восстанавливается только там, где установлен PostGIS.
- Деплой по образцу drugs: `/home/roombase/deploy.sh` (вне репо; pg_dump перед миграциями → `/home/roombase/backups`, хранится 3; смоук `/ru/`, `/ru/brands`, `/sitemap.xml` с `Host: roombase.ru`), лог `/home/roombase/deploy.log`. GH Actions: тесты на `postgis/postgis:18-3.6` → ssh с forced command. Первый деплой 2026-10-10 17:15 — OK.
- `.env.local` руками, ключей wearbase нет (LLM/поиск/TG/RuSender пусты, MAILER_DSN=null). Кронов/воркеров нет.
- Vhost шаблонный, не default_server; по IP не открывается — проверять `curl -H 'Host: roombase.ru' http://161.104.35.238/ru/` (200).
- TODO: делегирование у Regtime на a–d.ns.selectel.ru (зона в Selectel готова) → `certbot --nginx -d roombase.ru -d www.roombase.ru --redirect`, канон без www (образец — vhost pillbase.ru).

## Прочее на VDS
- `428.wearbase.ru` (сессия work-60, 2026-10-09): статика `/var/www/428/gallery.html`, vhost `sites-available/428.wearbase.ru`, noindex. LE-сертификат через `certbot --nginx --redirect` (80→443). DNS: явная A `428 → 161.104.35.238` в зоне reg.ru (перекрывает wildcard `*.wearbase.ru`) — ⚠️ перенести при переезде зоны.
- certbot: учётка LE создана БЕЗ email (`--register-unsafely-without-email`) — писем об истечении нет; продление `certbot.timer`. Привязать: `certbot update_account -m <email>`.
- vote428 (work-60): `vote428.service` на 127.0.0.1:8428, код `/opt/vote428`, данные `/var/lib/vote428/votes.db` (в ночном бэкапе — `vote428.tgz`), токен бота `/etc/vote428/bot_token` (в бэкапе через `/etc`). Вход — Telegram Login Widget (подпись локально, исходящих в TG нет). certbot dry-run renew — ок.
