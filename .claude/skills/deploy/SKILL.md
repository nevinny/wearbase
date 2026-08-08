---
name: deploy
description: Канонический деплой WEARBASE на прод (regru) — пре-флайт, полный rsync, миграции, cache:clear 512M, smoke-тест. Use when просят «задеплой», «выкати на прод», «деплой».
model: sonnet
---

> ℹ️ **С 2026-07-26 деплой обычно автоматический**: мерж PR в `main` → GitHub Actions сам катит на прод (тесты → rsync → миграции → cache:clear → smoke). Этот скилл — **ручной/срочный путь** (rsync с Mac, минуя GitHub): хотфикс в обход PR-флоу, отладка, или когда CI недоступен. Если правка уже в main через PR — деплой уже поехал, скилл не нужен. Схема CI/CD — память `wearbase-cicd-github-actions`.

Выполни деплой строго по **docs/production.md, раздел «Деплой (канонический порядок)»** — прочитай его сейчас (точные команды rsync/ssh живут там, не воспроизводи по памяти). Поверх него обязательный каркас:

**Пре-флайт (до rsync):**
1. `git status` — если у выкатываемой фичи есть untracked-файлы, останов: сначала закоммитить (грабли: фича из 12 untracked-файлов чуть не уехала частично).
2. Ветка main, локальные коммиты запушены (`git push`).
3. `php bin/console doctrine:migrations:status --no-debug` локально — сколько миграций поедет; если > 0, перечисли их пользователю в отчёте.

**Деплой:** полный rsync из корня проекта (НЕ отдельные файлы — см. «Грабли деплоя» в tasktracker), затем серверная часть из production.md.

⚠️ **Удалённые файлы основной rsync не убирает** (он без `--delete`, чтобы не снести прод-only данные) — на 26.07.2026 на проде так жили бутстраповый `templates/base.html.twig`, `showv2/showv3` и другой мёртвый код, который Symfony продолжает автозагружать. После основного rsync прогони второй проход ТОЛЬКО по каталогам чистого кода:

```bash
for DIR in src templates migrations translations; do
  rsync -az --delete --exclude .DS_Store ./$DIR/ regru:/var/www/u3042786/data/wearbase.ru/$DIR/
done
```

`public_html` (сгенерированные ассеты и загруженные картинки), `bin`, `var`, `config/secrets` в этот проход **не включать**. Перед первым применением на новом каталоге — `rsync -aznv --delete …` и глазами прочитать список `deleting`. ⚠️ На проде `cache:clear` ТОЛЬКО с `-d memory_limit=512M` (дефолт 128M — падает). ⚠️ На macOS нет команды `timeout` — не используй её в обвязке.

**Smoke-тест (после):**
1. `curl -s -o /dev/null -w '%{http_code}' https://wearbase.ru/ru/` → 200; `/ru/brands/` → 200; `/admin` → 302/200.
2. Хвост прод-лога на свежие ERROR: `ssh regru 'cd wearbase.ru && tail -50 var/log/prod.log | grep -i error | tail -5'` (пусто = ок).

**Отчёт** одним сообщением: диапазон уехавших коммитов (`git log --oneline`), применённые миграции, результаты smoke. Если smoke провалился — это блокер: доложи и предложи откат, сам не откатывай.
