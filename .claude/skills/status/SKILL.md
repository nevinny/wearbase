---
name: status
description: Сводка состояния WEARBASE одной командой — RAG-конвейер, дрип/публикации прода, свежесть синков GSC/Яндекс, health env, майнинг-риг. Use when пользователь спрашивает «как там конвейер», «статус», «что происходит», «как дела у дрипа».
---

Собери сводку. Все команды read-only; `PHP=/opt/homebrew/bin/php`, тяжёлое — с `-d memory_limit=512M`. Независимые команды запускай параллельно.

1. **Конвейер (Mac)**:
   - `php -d memory_limit=512M bin/console app:rag:doctor --no-debug` — инварианты/порча состояния;
   - `php bin/console dbal:run-sql "SELECT status, COUNT(*) c FROM brand_rag_pipeline GROUP BY status ORDER BY c DESC" --no-debug` — очереди по стадиям.
2. **Прод (дрип/публикации)**:
   - `ssh regru 'cd wearbase.ru && php bin/console app:brand:publish-tick --dry-run --no-debug'` — таргет дня, guards, опубликовано сегодня;
   - `ssh regru 'cd wearbase.ru && php bin/console dbal:run-sql "SELECT DATE(published_at) d, COUNT(*) c FROM brand WHERE published_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) GROUP BY d ORDER BY d" --no-debug'` — темп за 3 дня.
3. **Синки поисковиков (Mac)**: `php bin/console dbal:run-sql "SELECT MAX(day) FROM gsc_page_stats" --no-debug` и `php bin/console dbal:run-sql "SELECT MAX(last_checked_at) FROM yandex_index_status" --no-debug` — оба должны быть свежее 5 дней, иначе closed-loop не судит.
4. **Env-инварианты**: `php bin/console app:health:env --report --no-debug`.
5. **Майнинг-риг** (только если вопрос касался майнинга): `ssh llm 'nvidia-smi --query-gpu=name,temperature.gpu,utilization.gpu,power.draw --format=csv,noheader; df -h / | tail -1'`.

⚠️ `dbal:run-sql`: SQL строго одной строкой; «0 rows affected» на SELECT — артефакт раннера, не пустая таблица (перезапусти одной строкой).

Ответ — ОДНИМ сообщением ≤4000 символов: первым абзацем аномалии и тревоги (или «всё штатно»), затем цифры по блокам. Ничего не чинить без запроса — только докладывать.
