#!/usr/bin/env bash
# rig-watchdog.sh — сторож майнинга на риге. Крон: */5 * * * *
#
# Чему научила сессия 19.09.2026 (не выкидывать эти проверки):
#  * `tmux has-session -t mine` и поле mining_active дашборда ВРУТ: сессия жива,
#    а wildrig внутри — зомби. Живость мерить только по процессу wildrig-multi.
#  * Процесс, тронувший CUDA/UVM на этом хосте в клине, виснет в состоянии Zl/D и
#    не реапится; попытка перезапуска = ещё один Oops ядра в nvidia_uvm. Поэтому
#    при признаках клина сторож НИЧЕГО не перезапускает, только орёт.
#  * nvidia-smi не звать никогда: в тихом режиме (rig-quiet.sh) любой его вызов
#    затаскивает драйвер обратно в память и возвращает шум вентиляторов.
#  * pkill -f по имени скрипта убивает сам ssh-шелл — гасим только по PID.
#
# Выключатели:
#   ~/miner/.mining-enabled   — файла нет → сторож не поднимает майнер (следит и молчит)
#   ~/miner/.watchdog-off     — файл есть → сторож не делает вообще ничего

set -u
M=~/miner
LOG=$M/watchdog.log
DASH=http://127.0.0.1:8088
MAX_RESTARTS_PER_HOUR=3
STALL_SAMPLES=6          # 6 строк истории ≈ 3 мин нулевого хешрейта = застой

log(){ printf '%s %s\n' "$(date -u +'%F %T')" "$*" >> "$LOG"; }

# Алерт в телегу — best-effort, токен берём из env прода на риге, не хардкодим.
notify(){
  local msg="$1" env=~/wearbase/.env.local tok chat
  log "ALERT: $msg"
  [ -r "$env" ] || return 0
  tok=$(grep -m1 '^TELEGRAM_BOT_TOKEN=' "$env" | cut -d= -f2- | tr -d '"'\''' | tr -d '\r')
  chat=$(grep -m1 '^ADMIN_TELEGRAM_CHAT_ID=' "$env" | cut -d= -f2- | tr -d '"'\''' | tr -d '\r')
  [ -n "$tok" ] && [ -n "$chat" ] || return 0
  # Telegram из РФ напрямую не доступен — идём через локальный SOCKS (ssh-туннель
  # на швецию, ~/bin/socks-sweden.sh, порт 1081). Прямой запрос — как запасной путь.
  local api="https://api.telegram.org/bot$tok/sendMessage"
  curl -s -m 25 -o /dev/null --socks5-hostname 127.0.0.1:1081 "$api" \
       --data-urlencode "chat_id=$chat" --data-urlencode "text=🖥 риг: $msg" \
    || curl -s -m 20 -o /dev/null "$api" \
       --data-urlencode "chat_id=$chat" --data-urlencode "text=🖥 риг: $msg" \
    || true
}

# Алерт не чаще раза в N секунд на один ключ (чтобы не залить телегу).
notify_throttled(){
  local key="$1" period="$2" msg="$3"
  local f="$M/.alert-$key"
  local now; now=$(date +%s)
  if [ -f "$f" ] && [ $(( now - $(cat "$f" 2>/dev/null || echo 0) )) -lt "$period" ]; then return 0; fi
  echo "$now" > "$f"; notify "$msg"
}

restarts_last_hour(){
  local f=$M/.watchdog-restarts
  local now; now=$(date +%s)
  [ -f "$f" ] || { echo 0; return; }
  awk -v n="$now" '$1 > n-3600' "$f" | wc -l
}
record_restart(){
  local f=$M/.watchdog-restarts
  local now; now=$(date +%s)
  echo "$now" >> "$f"
  awk -v n="$now" '$1 > n-86400' "$f" > "$f.tmp" 2>/dev/null && mv "$f.tmp" "$f"
}

[ -f "$M/.watchdog-off" ] && exit 0

# --- 1. тихий режим: драйвер выгружен намеренно, не мешаем ---------------------
if ! lsmod | grep -q '^nvidia '; then
  log "тихий режим (драйвер выгружен) — пропуск"
  exit 0
fi

# --- 2. клин GPU-стека: Oops в nvidia_uvm за текущую загрузку -------------------
OOPS=$(journalctl -k -b --no-pager 2>/dev/null | grep -c 'Oops:')
if [ "${OOPS:-0}" -gt 0 ]; then
  notify_throttled wedged 21600 "GPU-стек в клине: $OOPS Oops в ядре с загрузки (nvidia_uvm). Майнинг и ollama не поднять, нужен ребут. Сторож ничего не трогает."
  log "WEDGED: oops=$OOPS — ничего не перезапускаю"
  exit 0
fi

# --- 3. зависшие GPU-процессы (Zl/D не реапятся) --------------------------------
STUCK=$(ps -eo stat=,comm= | awk '$1 ~ /^[DZ]/ && ($2=="wildrig-multi" || $2=="llama-server" || $2=="ollama" || $2=="python")' | wc -l)
if [ "${STUCK:-0}" -gt 0 ]; then
  notify_throttled stuck 21600 "залипшие GPU-процессы: $STUCK шт. в D/Z — перезапуск только усугубит, нужен ребут."
  log "STUCK procs=$STUCK — ничего не перезапускаю"
  exit 0
fi

# --- 4. дашборд ------------------------------------------------------------------
if ! curl -sf -m 8 -o /dev/null "$DASH/data"; then
  if [ "$(restarts_last_hour)" -ge "$MAX_RESTARTS_PER_HOUR" ]; then
    notify_throttled ratelimit 3600 "дашборд лежит, но лимит рестартов исчерпан — смотреть руками."
    exit 0
  fi
  log "дашборд не отвечает — рестарт rig-dashboard"
  systemctl --user restart rig-dashboard
  record_restart
  notify_throttled dash 3600 "дашборд :8088 не отвечал, перезапустил."
  exit 0
fi

# --- 5. майнинг armed? -----------------------------------------------------------
[ -f "$M/.mining-enabled" ] || { log "майнинг не armed (.mining-enabled нет) — только слежу"; exit 0; }

# --- 6. жив ли реально wildrig (а не только tmux-сессия) -------------------------
ALIVE=0
PID=$(pgrep -x wildrig-multi | head -1 || true)
if [ -n "${PID:-}" ]; then
  ST=$(ps -o stat= -p "$PID" 2>/dev/null | tr -d ' ')
  case "$ST" in
    [DZ]*) log "wildrig pid=$PID в состоянии $ST — считаю мёртвым и НЕ трогаю"
           notify_throttled zombie 21600 "wildrig завис (stat=$ST), процесс не убивается — нужен ребут."
           exit 0 ;;
    *)     ALIVE=1 ;;
  esac
fi

if [ "$ALIVE" = "0" ]; then
  if [ "$(restarts_last_hour)" -ge "$MAX_RESTARTS_PER_HOUR" ]; then
    notify_throttled ratelimit 3600 "майнер падает чаще $MAX_RESTARTS_PER_HOUR раз/час — остановился, разбирайся руками."
    log "лимит рестартов исчерпан — не поднимаю"
    exit 0
  fi
  log "wildrig не найден — старт через дашборд"
  curl -s -m 20 -X POST "$DASH/start" >> "$LOG" 2>&1
  echo >> "$LOG"
  record_restart
  exit 0
fi

# --- 7. майнер жив, но хешрейт стоит --------------------------------------------
UP=$(ps -o etimes= -p "$PID" 2>/dev/null | tr -d ' ')
if [ "${UP:-0}" -lt 300 ]; then log "wildrig pid=$PID uptime ${UP}s — даю разогнаться"; exit 0; fi

ZERO=$(tail -n "$STALL_SAMPLES" "$M/hashrate-history.csv" 2>/dev/null | awk -F, '$2+0==0' | wc -l)
if [ "${ZERO:-0}" -ge "$STALL_SAMPLES" ]; then
  if [ "$(restarts_last_hour)" -ge "$MAX_RESTARTS_PER_HOUR" ]; then
    notify_throttled ratelimit 3600 "хешрейт 0 при живом wildrig, лимит рестартов исчерпан."
    exit 0
  fi
  log "хешрейт 0 последние $STALL_SAMPLES замеров при живом wildrig — перезапуск"
  curl -s -m 20 -X POST "$DASH/stop" >/dev/null 2>&1
  sleep 5
  curl -s -m 20 -X POST "$DASH/start" >> "$LOG" 2>&1
  echo >> "$LOG"
  record_restart
  notify_throttled stall 3600 "хешрейт был 0 при живом майнере — перезапустил."
  exit 0
fi

HR=$(tail -n 1 "$M/hashrate-history.csv" 2>/dev/null | cut -d, -f2)
log "ok: wildrig pid=$PID up=${UP}s hr=${HR:-?}"
exit 0
