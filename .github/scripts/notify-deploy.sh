#!/usr/bin/env bash
# Формирует и отправляет в Telegram (группа Wearbase_admin) уведомление о
# результате прод-деплоя. Вызывается из .github/workflows/ci.yml, шаг
# "Notify Telegram" в джобе "Deploy to prod (regru)".
#
# Локальный прогон (без секретов и сети, только сборка текста):
#   JOB_STATUS=success \
#   OUTCOME_RSYNC=success OUTCOME_PRUNE=success OUTCOME_MIGRATE=success OUTCOME_SMOKE=success \
#   COMMIT_MESSAGE=$'fix(x): "quotes" <tag> & amp (#12)' \
#   COMMIT_SHA=abcdef1234567890 COMMIT_AUTHOR=zyablik REPO=key-group/wearbase \
#   BEFORE_SHA=0000000000000000000000000000000000000000 \
#   RUN_URL=https://github.com/key-group/wearbase/actions/runs/1 \
#   MIGRATE_LOG_FILE=/tmp/migrate.log SMOKE_LOG_FILE=/tmp/smoke.log \
#   DRY_RUN=1 bash .github/scripts/notify-deploy.sh
#
# Для проверки реальной отправки задать BOT_TOKEN/CHAT_ID и убрать DRY_RUN.
# Для проверки блока "файлы" без сети — COMPARE_JSON_FILE=/path/to/fixture.json
# (формат — тело ответа GitHub compare API).

set -uo pipefail

escape_html() {
  # ⚠️ bash 5.x (GH runners включая; homebrew bash на Mac) трактует НЕЭКРАНИРОВАННЫЙ
  # "&" в replacement ${var//pattern/replacement} как ссылку на совпавший фрагмент
  # (как sed) — без "\&" вместо "&lt;" получаем "<lt;". bash 3.2 (системный на Mac)
  # так не делает, поэтому баг не виден при поверхностном тесте — экранировать всегда.
  local s=$1
  s=${s//&/\&amp;}
  s=${s//</\&lt;}
  s=${s//>/\&gt;}
  printf '%s' "$s"
}

JOB_STATUS=${JOB_STATUS:-failure}
OUTCOME_RSYNC=${OUTCOME_RSYNC:-}
OUTCOME_PRUNE=${OUTCOME_PRUNE:-}
OUTCOME_MIGRATE=${OUTCOME_MIGRATE:-}
OUTCOME_SMOKE=${OUTCOME_SMOKE:-}
COMMIT_MESSAGE=${COMMIT_MESSAGE:-}
COMMIT_SHA=${COMMIT_SHA:-0000000000000000000000000000000000000000}
COMMIT_AUTHOR=${COMMIT_AUTHOR:-unknown}
REPO=${REPO:-}
BEFORE_SHA=${BEFORE_SHA:-}
RUN_URL=${RUN_URL:-}
MIGRATE_LOG_FILE=${MIGRATE_LOG_FILE:-}
SMOKE_LOG_FILE=${SMOKE_LOG_FILE:-}
COMPARE_JSON_FILE=${COMPARE_JSON_FILE:-}
DRY_RUN=${DRY_RUN:-0}
BOT_TOKEN=${BOT_TOKEN:-}
CHAT_ID=${CHAT_ID:-}

SHORT_SHA=${COMMIT_SHA:0:7}
TITLE_RAW=$(printf '%s\n' "$COMMIT_MESSAGE" | head -n1)
TITLE=$(escape_html "$TITLE_RAW")
AUTHOR=$(escape_html "$COMMIT_AUTHOR")
# Ссылка на диф имеет смысл только когда известен предыдущий sha: при force-push и
# первом пуше в ветку github.event.before — нули, compare по ним отдаёт 404.
LINKS="<a href=\"${RUN_URL}\">лог</a>"
if [[ -n "$BEFORE_SHA" && ! "$BEFORE_SHA" =~ ^0+$ ]]; then
  LINKS="${LINKS} · <a href=\"https://github.com/${REPO}/compare/${BEFORE_SHA}...${COMMIT_SHA}\">диф</a>"
fi

find_failed_step() {
  local names=(rsync prune migrate smoke)
  local labels=("Rsync to prod" "Prune deleted code files" "Migrate + clear cache" "Smoke test")
  local outcomes=("$OUTCOME_RSYNC" "$OUTCOME_PRUNE" "$OUTCOME_MIGRATE" "$OUTCOME_SMOKE")
  local i
  for i in "${!names[@]}"; do
    if [[ "${outcomes[$i]}" == "failure" ]]; then
      printf '%s' "${labels[$i]}"
      return 0
    fi
  done
  printf '%s' "до шага деплоя (checkout/build/ssh)"
}

if [[ "$JOB_STATUS" != "success" ]]; then
  STEP=$(find_failed_step)
  MSG="🔴 Деплой упал · шаг: ${STEP}
<b>${TITLE}</b>
${AUTHOR} · <code>${SHORT_SHA}</code>
${LINKS}"
else
  # Миграции: прод на doctrine/migrations 3.9.4 (НЕ 2.x — "++ migrating" там нет).
  # Реальный формат вывода `migrations:migrate --no-interaction`:
  #   [notice] Migrating up to DoctrineMigrations\VersionXXXX      — целевая (последняя) версия,
  #                                                                   одна строка, без переносов
  #   [notice] finished in ...ms, used ..., N migrations executed, ... — счётчик применённых
  #   [OK] Successfully migrated to version:                       — SymfonyStyle-блок; при узком
  #        DoctrineMigrations\VersionXXXX                             терминале версия уходит на
  #                                                                    следующую строку с отступом
  #   [OK] Already at the latest version ("...")                   — если применять было нечего
  # Имён ВСЕХ применённых версий в выводе нет, только целевая. Между строк может затесаться
  # JSON-шум deprecation-логгера — грепаем по строгим якорям, шум под них не подходит.
  MIGRATIONS="нет"
  if [[ -n "$MIGRATE_LOG_FILE" && -f "$MIGRATE_LOG_FILE" ]]; then
    if ! grep -qE '^\[notice\] Already at the .* version|No migrations to execute\.' "$MIGRATE_LOG_FILE"; then
      COUNT=$(grep -oE '[0-9]+ migrations executed' "$MIGRATE_LOG_FILE" | head -n1 | grep -oE '^[0-9]+')
      TARGET=$(grep -E '^\[notice\] Migrating( \(dry-run\))? (up|down) to ' "$MIGRATE_LOG_FILE" \
        | head -n1 | grep -oE 'Version[0-9A-Za-z_]+')
      if [[ -z "$TARGET" ]]; then
        TARGET=$(grep -A2 'Successfully migrated to version' "$MIGRATE_LOG_FILE" \
          | grep -oE 'Version[0-9A-Za-z_]+' | head -n1)
      fi
      if [[ -n "$COUNT" ]]; then
        if [[ "$COUNT" == "1" || -z "$TARGET" ]]; then
          MIGRATIONS="${COUNT} применено"
        else
          MIGRATIONS="${COUNT} применено (до $(escape_html "$TARGET"))"
        fi
      elif [[ -n "$TARGET" ]]; then
        # счётчик не распарсился, но факт миграции виден — не молчим об этом
        MIGRATIONS="применено (до $(escape_html "$TARGET"))"
      elif grep -qE '^\[notice\] Migrating( \(dry-run\))? (up|down) to ' "$MIGRATE_LOG_FILE"; then
        MIGRATIONS="применено (детали см. в логе)"
      fi
    fi
  fi

  # Файлы + diff stat через GitHub compare API (или локальную фикстуру для теста)
  FILES_LINE="Файлы: —"
  if [[ -n "$BEFORE_SHA" && ! "$BEFORE_SHA" =~ ^0+$ ]]; then
    COMPARE_JSON=""
    if [[ -n "$COMPARE_JSON_FILE" && -f "$COMPARE_JSON_FILE" ]]; then
      COMPARE_JSON=$(cat "$COMPARE_JSON_FILE")
    elif [[ -n "$REPO" ]] && command -v gh >/dev/null 2>&1; then
      COMPARE_JSON=$(gh api "repos/${REPO}/compare/${BEFORE_SHA}...${COMMIT_SHA}" 2>/dev/null)
    fi
    if [[ -n "$COMPARE_JSON" ]] && echo "$COMPARE_JSON" | jq -e '.files' >/dev/null 2>&1; then
      TOTAL=$(echo "$COMPARE_JSON" | jq '.files | length')
      ADD=$(echo "$COMPARE_JSON" | jq '[.files[].additions] | add // 0')
      DEL=$(echo "$COMPARE_JSON" | jq '[.files[].deletions] | add // 0')
      NAMES=$(echo "$COMPARE_JSON" | jq -r '[.files[].filename] | .[0:8] | join(", ")')
      NAMES=$(escape_html "$NAMES")
      if [[ "$TOTAL" -gt 8 ]]; then
        EXTRA=$((TOTAL - 8))
        NAMES="${NAMES} и +${EXTRA} ещё"
      fi
      FILES_LINE="Файлы (${TOTAL}): ${NAMES} (+${ADD}/−${DEL})"
    fi
  fi

  # Smoke test
  SMOKE_LINE="Smoke: —"
  if [[ -n "$SMOKE_LOG_FILE" && -f "$SMOKE_LOG_FILE" ]]; then
    SMOKE_BODY=$(paste -sd '|' "$SMOKE_LOG_FILE" | sed 's/|/ · /g')
    if [[ -n "$SMOKE_BODY" ]]; then
      SMOKE_LINE="Smoke: $(escape_html "$SMOKE_BODY")"
    fi
  fi

  MSG="✅ Прод обновлён · wearbase.ru
<b>${TITLE}</b>
${AUTHOR} · <code>${SHORT_SHA}</code>
Миграции: ${MIGRATIONS}
${FILES_LINE}
${SMOKE_LINE}
${LINKS}"
fi

printf '%s\n' "$MSG"

if [[ "$DRY_RUN" == "1" ]]; then
  exit 0
fi

if [[ -z "$BOT_TOKEN" || -z "$CHAT_ID" ]]; then
  echo "notify-deploy: BOT_TOKEN/CHAT_ID не заданы, отправка пропущена" >&2
  exit 0
fi

RESPONSE=$(curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
  --data-urlencode "chat_id=${CHAT_ID}" \
  --data-urlencode "parse_mode=HTML" \
  --data-urlencode "disable_web_page_preview=true" \
  --data-urlencode "text=${MSG}")

OK=$(echo "$RESPONSE" | jq -r '.ok' 2>/dev/null)
echo "notify-deploy: telegram ok=${OK:-false}"
if [[ "$OK" != "true" ]]; then
  exit 1
fi
