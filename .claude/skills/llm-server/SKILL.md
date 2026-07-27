---
name: llm-server
description: Диагностика GPU-сервера (ssh llm) — ollama, Qdrant, SearXNG, GPU, диск, RAM; восстановление после смены IP. Use when «проверь llm-сервер», «сервер переехал/недоступен», «не работает генерация/эмбеддинги», «не overbook ли сервер».
model: haiku
---

Сервер: майнинг-риг с GPU, ollama :11434 (генерация + эмбеддинги), Qdrant :6333, SearXNG :8080. SSH-алиас `llm` в `~/.ssh/config`. ⚠️ IP непостоянен (DHCP, исторически .109/.111/.119/.43/2.115). Урлы сервисов — в `.env.local` (`LOCAL_LLM_URL`, `LOCAL_EMBED_URL`, `QDRANT_URL`, `SEARXNG_URL`); ключ Qdrant не цитировать в ответах.

## Проверка (независимое — параллельно)

1. Хост: `ssh llm uptime` и `ssh llm free -h | head -2`
2. GPU: `ssh llm nvidia-smi --query-gpu=name,temperature.gpu,utilization.gpu,power.draw --format=csv,noheader`
3. Диск (забивался в 100%): `ssh llm df -h / | tail -1`
4. ollama: `curl -s -m 5 "$(grep '^LOCAL_LLM_URL' .env.local | cut -d= -f2)/api/tags" | jq -r '.models[].name'` и что в VRAM: `ssh llm ollama ps`
5. Qdrant: `curl -s -m 5 -H "api-key: $(grep '^QDRANT_API_KEY' .env.local | cut -d= -f2)" "$(grep '^QDRANT_URL' .env.local | cut -d= -f2)/collections" | jq -r '.result.collections[].name'`

«Overbook» = GPU util стабильно ~100% + занятая RAM под потолок + в `ollama ps` больше моделей, чем нужно конвейеру. Перед скачиванием новых моделей проверять `df -h` и свободную RAM (модели ~20 ГБ могут не влезть).

## Если недоступен (вероятно, сменился IP)

1. Скан подсети по порту ollama (IP видели и в .0.x, и в .2.x): `for net in 192.168.0 192.168.2; do for i in $(seq 2 254); do (nc -z -G 1 $net.$i 11434 2>/dev/null && echo "$net.$i") & done; done; wait`
2. Нашёлся новый IP → обновить: `HostName` в `~/.ssh/config` (Host llm), урлы в `.env.local` (LOCAL_LLM_URL, LOCAL_EMBED_URL, QDRANT_URL, SEARXNG_URL).
3. `Host key verification failed` → `ssh-keygen -R <старый-ip>` и повторить ssh.
4. Зафиксировать новый IP в CLAUDE.md (раздел про хосты), чтобы следующая сессия не искала заново.

Ответ — ОДНИМ сообщением: первым абзацем вердикт (жив/переехал/перегружен), затем цифры. Ничего не перезапускать на сервере без явной просьбы.
