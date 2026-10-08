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
