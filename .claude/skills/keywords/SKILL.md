---
name: keywords
description: Сбор ключевиков по брендам (Yandex Wordstat) — батч, точечно по бренду, прогресс и результаты. Use when «собери/запусти сбор ключевиков», «ключевики по новым/недавно добавленным брендам», «есть ли ключевики у бренда X», «как идёт сбор».
model: sonnet
---

`PHP=/opt/homebrew/bin/php`; нужен `WORDSTAT_API_KEY` (env). Команда `app:brand:keywords` сама берёт бренды, у которых ключевики ещё не собирались (`brand_rag_pipeline.keywords_status IS NULL`) — «недавно добавленные» покрываются автоматически, отдельного флага recent нет.

## Запуск

- **Перед батчем** проверь, что сбор уже не идёт: `pgrep -fl 'app:brand:keywords'` — второй поверх первого не запускать.
- Точечно: `php bin/console app:brand:keywords --id=<id> --no-debug` (`--force` — пересобрать заново, `--dry-run` — показать без сохранения).
- Небольшой батч (≤100): `php -d memory_limit=512M bin/console app:brand:keywords 100 --no-debug`
- Долгий батч (сотни+, часы) — только в фоне с логом:
  `nohup php -d memory_limit=512M bin/console app:brand:keywords 6000 --quiet --no-debug >> var/log/kw.log 2>&1 &`
  Без `--no-debug` профайлер Doctrine съест память (~750 брендов при 512M — OOM).

## Прогресс и результаты

⚠️ `dbal:run-sql`: SQL одной строкой; «0 rows affected» на SELECT — артефакт раннера.

- Прогресс: `php bin/console dbal:run-sql "SELECT keywords_status, COUNT(*) c FROM brand_rag_pipeline GROUP BY keywords_status" --no-debug` (`NULL` = ещё не собирались) + `tail -20 var/log/kw.log`.
- Ключевики бренда: `php bin/console dbal:run-sql "SELECT keyword, type, monthly_shows, source FROM brand_keyword WHERE brand_id=<id> ORDER BY monthly_shows DESC LIMIT 20" --no-debug`
- Результат используется генерацией контента (`app:brand:generate-content`): топ-фразы вплетаются в title — после `--force`-пересбора контент сам не обновится.

Ответ — ОДНИМ сообщением: что запущено/найдено, ожидаемое время, как проверить прогресс.
