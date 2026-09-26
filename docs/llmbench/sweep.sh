#!/bin/bash
# Перебор раскладок ollama по картам. Запуск: sudo ~/llmbench/sweep.sh
# Каждый вариант = чистый restart (смена опций раннера в живом процессе -> двойная загрузка -> OOM-kill по MemoryMax=6G, проверено 2026-09-24).
# Всегда возвращает исходный 20-tuning.conf (trap), 30-memory-guard.conf не трогает.
set -u
D=/etc/systemd/system/ollama.service.d; F=$D/20-tuning.conf; B=$D/20-tuning.conf.bak-sweep-$(date +%Y%m%d-%H%M)
G0=GPU-1eb1e426-0331-0439-d310-b94869fb5b6d   # 2060S #0
G1=GPU-a73d459d-76b6-626e-a47c-dd336d2e1143   # 2060S #1 (мёртвый вентилятор)
A4=GPU-d5a6e9cf-47c0-a626-c1f6-8e05580f7a55   # A4000
C3=GPU-86d39369-0a5c-6e2f-9dfd-36441971a0fb   # CMP 30HX
C4=GPU-3e16dce2-264a-b5cd-326d-79849ce865e2   # CMP 30HX
cp -a "$F" "$B"
restore() { cp -a "$B" "$F"; systemctl daemon-reload; systemctl restart ollama; echo "restored $F"; }
trap restore EXIT
apply() { # $1=devices $2=extra env lines
  { echo "[Service]"; echo "Environment=CUDA_VISIBLE_DEVICES=$1"; echo "Environment=OLLAMA_NUM_PARALLEL=1";
    echo "Environment=OLLAMA_CONTEXT_LENGTH=8192"; echo "Environment=OLLAMA_KEEP_ALIVE=-1"; printf "%b" "${2:-}"; } > "$F"
  systemctl daemon-reload; systemctl restart ollama
  for i in $(seq 1 60); do curl -s -m 2 localhost:11434/api/version >/dev/null && break; sleep 2; done
}
run() { sudo -u zyablik python3 /home/zyablik/llmbench/bench.py "$1" "${2:-null}" | tee -a /home/zyablik/llmbench/sweep.log; }
apply "$A4,$G0";                 run "A4000+2060S0"
apply "$A4,$G0,$C3";             run "A4000+2060S0+CMP3"
apply "$A4,$C3,$C4";             run "A4000+CMP3+CMP4"
apply "$A4,$G0,$G1";             run "A4000+2060S0+2060S1"
apply "$A4,$G0" "Environment=OLLAMA_FLASH_ATTENTION=1\n";  run "A4000+2060S0+FA1"
apply "$A4,$G0" "Environment=OLLAMA_FLASH_ATTENTION=0\n";  run "A4000+2060S0+FA0"
apply "$G0,$G1,$A4,$C3,$C4";                 run "all5+batch1024" "{\"num_batch\":1024}"
apply "$G0,$G1,$A4,$C3,$C4";                 run "all5+batch2048" "{\"num_batch\":2048}"
apply "$A4,$G0";                 run "A4000+2060S0+batch1024" "{\"num_batch\":1024}"
