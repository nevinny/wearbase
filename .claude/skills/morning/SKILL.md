---
name: morning
description: Утренний бриф — что было вчера и что в работе. tasktracker + git log + цифры дайджеста + состояние экспериментов. Use when «что было вчера», «введи в курс», «утренний бриф», «с чего начать», «поясни последние изменения».
model: sonnet
---

Собери бриф (PHP=/opt/homebrew/bin/php; независимое — параллельно):

1. **Коммиты**: `git log --since='yesterday 06:00' --oneline` (пусто → расширь до `--since='3 days ago'`).
2. **Tasktracker**: хвост `docs/tasktracker.md` — последние 1–2 датированные секции (заголовки `### ДД.ММ`), особенно «остаток ручных шагов» и ⚠️-пометки.
3. **Цифры дня**: `php bin/console app:report:daily --stdout-only --no-debug` (публикации прода, GSC, Яндекс, контакты).
4. **Эксперименты closed-loop**: `php bin/console dbal:run-sql "SELECT verdict, COUNT(*) c FROM brand_content_revision GROUP BY verdict" --no-debug` + `php bin/console dbal:run-sql "SELECT MIN(measure_after) next_window FROM brand_content_revision WHERE verdict='pending'" --no-debug` — когда ближайшие вердикты.
5. **Незакрытое**: `git status -s` (не-pyc modified/untracked = брошенная работа).

Ответ — ОДНИМ сообщением ≤4000 символов, три части: 1) что произошло (по коммитам/трекеру, человеческим языком, без перечисления всех коммитов подряд — только смысл); 2) что ждёт решения/действия пользователя (ручные шаги из трекера, аномалии в цифрах); 3) ключевые цифры одной строкой. Не начинать никакую работу — это бриф.
