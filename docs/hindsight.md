# Hindsight (vectorize-io) — память для агентов: разбор 2026-09-25

**Вывод:** зрелый open-source продукт (MIT, ~28k★, релизы каждые 1–3 недели, v0.10.1 от 2026-09-21), не «только статья».
Сейчас WEARBASE **не нужен**: память сессий Claude Code уже закрыта файловой памятью, память стилиста гардероба —
`WardrobeOutfitLearningService` (`preference_context`). Кандидат на будущее — память бизнес-советника/TG-бота, если
понадобится «учиться» на истории диалогов. Ставить — на Mac (Docker), **не на риг**.

## Что это
- Сервер памяти агента: API :8888 (REST + MCP `http://localhost:8888/mcp/{bank_id}/`), UI :9999; SDK Python/TS, обёртка LiteLLM,
  интеграции LangGraph/LlamaIndex/CrewAI, плагины для coding-агентов.
- Память живёт в «банках»; два пути — world facts и experiences; поверх — observations, mental models / knowledge pages.
- Операции: **retain** (LLM извлекает факты, сущности, связи, время → нормализация), **recall** (4 стратегии параллельно:
  семантика, BM25, граф, время → RRF + cross-encoder rerank), **reflect** (ответ/синтез с учётом накопленного).
- Хранилище — PostgreSQL (встроенный `pg0` в embedded-режиме, или внешний через docker compose / Helm).
- LLM: 25+ провайдеров, **включая локальные `ollama`, `llamacpp`, `lmstudio`** и любой OpenAI-совместимый endpoint;
  есть провайдер `claude-code` (подписка Claude Pro/Max).

## Бенчмарки (заявлено авторами)
- SOTA на LongMemEval (на январь 2026, график в README); живая таблица — benchmarks.hindsight.vectorize.io.
- Статья arXiv:2512.12818 (дек. 2025): LoCoMo ~89.6 %.
- README: результаты Hindsight «независимо воспроизведены» Virginia Tech (Sanghani Center) и The Washington Post;
  цифры конкурентов — самоотчёты вендоров. Сами не проверяли.

## Применимость к нашей инфраструктуре
- **Риг — нет.** 7.6 ГБ RAM (ollama MemoryMax=6G + Qdrant уже впритык, сегодняшние OOM), слабый CPU;
  Postgres + (вероятно) локальные embedding/rerank-модели на CPU — не подтверждено README, проверять по docs перед установкой.
- Каждый retain = LLM-вызов → третий потребитель очереди ollama рядом с WEARBASE и miner-dash (`llm-worker`).
- Если пробовать: Docker на Mac, LLM-провайдер `ollama` на риг (`http://192.168.2.43:11434`) или `claude-code`.

⚠️ Первичный разбор дешёвым агентом ошибочно заявил «кода нет, только статья» — перепроверено по GitHub API и README.

Источники: https://github.com/vectorize-io/hindsight (README, releases), https://hindsight.vectorize.io/, arXiv:2512.12818.
