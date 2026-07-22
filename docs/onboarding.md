# Онбординг нового разработчика (локальный запуск)

Этот документ — для свежего клона репозитория. `.env` и `.env.local` **не коммитятся**
(содержат секреты) — поэтому после `git clone` проект не запустится без ручной настройки.

## Требования

- **PHP 8.2+** (см. `composer.json`). На Mac — `/opt/homebrew/bin/php` (Homebrew); на других ОС —
  системный PHP 8.2+, лишь бы были расширения `ext-ctype`, `ext-iconv`, `pdo_mysql`.
- **Composer**.
- **Локальный MySQL 8.x/9.x** — не SQLite. Схема БД (миграции, FK-констрейнты вроде
  `country.id INT UNSIGNED`) написана под MySQL-синтаксис и на SQLite не разворачивается.
- **Node.js** — опционально, только для E2E-тестов (Playwright).

## Шаги запуска

```bash
# 1. Зависимости
composer install

# 2. Локальный MySQL: поставить (brew install mysql / apt / докер — на твой выбор),
#    запустить, создать БД и пользователя, например:
mysql -u root -e "CREATE DATABASE wearbase CHARACTER SET utf8mb4;"
mysql -u root -e "CREATE USER 'wearbase'@'localhost' IDENTIFIED BY 'change_me';"
mysql -u root -e "GRANT ALL ON wearbase.* TO 'wearbase'@'localhost';"

# 3. Конфиг
cp .env.local.example .env.local
# отредактировать .env.local:
#   - DATABASE_HOST/PORT/USER/PASSWORD/NAME под свою установку MySQL из шага 2
#   - APP_SECRET можно скопировать из .env.dev (там дефолтный dev-секрет) или сгенерировать
#     любую случайную строку — на локальной разработке критичности нет

# 4. Схема БД
php -d memory_limit=512M bin/console doctrine:migrations:migrate

# 5. Запуск
symfony serve
# либо без Symfony CLI: php -S 127.0.0.1:8000 -t public_html
```

Проверка: `http://127.0.0.1:8000/ru/` должен открыть каталог (пустой, без брендов — это ок).

## Про SQLite

Dev-окружение на SQLite **не поддерживается** — это тупик: миграции и часть схемы
MySQL-специфичны (`country.id INT UNSIGNED` + FK на него, синтаксис миграций).
Не пытайся подменить `DATABASE_URL` на `sqlite://...` для dev.

SQLite используется **только** для PHPUnit (`var/test.db`, `.env.test`) — там схема
автогенерируется из текущих сущностей при каждом прогоне тестов (`tests/bootstrap.php`),
это отдельный изолированный контур и трогать его не нужно. Подробности — [testing.md](testing.md).

## Про локальный LLM-сервер и RAG

В проекте есть RAG-конвейер (генерация контента брендов, эмбеддинги, LLM) на железе,
которое стоит в локальной сети владельца проекта (ollama/Qdrant/SearXNG). Оно недоступно
откуда-либо ещё и **не нужно для локальной разработки**.

Если `LOCAL_LLM_URL` и подобные переменные пустые — команды генерации контента, RAG-конвейер
(`discover → fetch → embed → generate-content`) просто не будут работать. Это нормально,
остальное приложение (каталог, ЛК бренда, платежи, админка и т.д.) от этого не зависит.

Если нужно локально погонять генерацию контента — пропиши свой `OPENROUTER_API_KEY` +
`OPENROUTER_MODEL` (облачный LLM через OpenRouter, платный, но работает у любого без LAN).
Для чистой фронт/бэк/каталог-разработки никакой LLM не требуется вообще.

## Разработка гардероба (AI-подсказки)

Гардероб (`WardrobeAiService`) использует **vision-модель**, а не текстовую gemma с рига.
При `WARDROBE_VISION_LOCAL=0` (дефолт из committed `.env`) фото/URL-подсказки идут в
**облако через OpenRouter** (`google/gemini-2.5-flash-lite`) — **LAN-сервер не нужен**.

Что достаточно сделать:
1. Вписать свой `OPENROUTER_API_KEY` в `.env.local` (см. подсказку в `.env.local.example`).
2. Оставить `WARDROBE_VISION_LOCAL=0` (не ставить `=1` — это путь на риг в LAN).

`WARDROBE_VISION_MODEL` и `WARDROBE_AI_DAILY_CAP` уже заданы в committed `.env` — трогать
не нужно. Модель `gemini-2.5-flash-lite` дешёвая, дневной кап (100) страхует от перерасхода.
Кэш подсказок 24ч (по sha1 фото / нормализованному URL) — повторный тот же запрос бюджет не жрёт.

## memory_limit

Дефолтный `memory_limit` у Homebrew PHP — 128M, этого мало для `cache:clear` и батчевых
консольных команд. Всегда добавляй `-d memory_limit=512M`:

```bash
php -d memory_limit=512M bin/console cache:clear
```
