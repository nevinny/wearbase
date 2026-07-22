# LLM-relay — доступ к локальной ollama из интернета (handoff)

> Дополнение к [`llm_infra_handoff.md`](llm_infra_handoff.md). Тот документ описывает
> сам локальный AI-сервер (риг: ollama/gemma4:26b/Qdrant, только LAN). Этот — как
> **потреблять его из облака/прода**, когда потребитель не в LAN.
> Снято вживую 2026-07-18. Токен/пути реальные, актуализируйте при изменениях.

## TL;DR для нового потребителя

Локальная ollama доступна как **OpenAI-совместимый эндпоинт** через HTTP-relay:

```
POST https://forgetborders.com/llmq.php?action=submit&token=<TOKEN>
Content-Type: application/json
{"model":"gemma4:26b","max_tokens":64,"messages":[{"role":"user","content":"..."}]}
→ 200 {"choices":[{"message":{"content":"..."}}],"usage":{...}}   (OpenAI-формат, синхронно)
```

- `TOKEN` = `afbef1952cdeaa95d3756aa8d09af86f2b68dbcc15c0e971` (общий секрет этой инфры).
- Модель фактически всегда `gemma4:26b` (воркер игнорирует присланный `model`).
- Латентность ~5с/вызов, **GPU concurrency ≈ 1** — не долбите параллельно, сериализуйте.
- Синхронный long-poll: submit держит соединение до ~25с и отдаёт ответ. Клиентский
  HTTP-таймаут ставьте **≥ 40с**.
- Ответ gemma приходит уже с **снятыми ```` ```json ````-фенсами** (воркер чистит).

Всё. Для интеграции достаточно указать этот URL как OpenAI chat-эндпоинт.

## Зачем relay, а не туннель (тупики — не повторять)

Риг за домашним NAT, аплинк нестабильный. Проверено 2026-07-18:
- **Cloudflare Tunnel** (quick и named): контрольное соединение регистрируется, но
  data-плоскость чёрно-дырится (`no recent network activity`, error 1033) — MTU/middlebox
  на аплинке рига. Порт 7844/443 при этом OPEN. Named-туннель на своём домене НЕ поможет —
  проблема не в DNS.
- **Reverse-SSH на reg.ru**: `AllowTcpForwarding` запрещён (`remote port forwarding failed`).
- **Reverse-SSH на forgetborders**: SSH на хостинге нет вообще (только PHP/HTTP).
- **Короткие исходящие HTTPS с рига — идеально стабильны** (200 за ~0.2с).

Вывод: persistent-туннель в этой среде не живёт. Работает **pull-модель**: риг сам
дёргает публичный HTTP-эндпоинт короткими запросами.

## Архитектура

```
ПОТРЕБИТЕЛЬ (прод/облако)                forgetborders.com (shared PHP)         РИГ (ssh llm, LAN)
   │ POST ?action=submit  (OpenAI) ───────►  llmq.php  ── файловая очередь ──┐
   │ (long-poll ждёт результат)              pending/ working/ done/         │
   │                                                ▲   GET ?action=claim ───┤ llm_worker.php (loop)
   │                                                │   POST ?action=complete│   claim → ollama /api/chat
   ◄── OpenAI chat.completion ───────────────  done/<id>.res  ◄──────────────┘   (think:false) → fence-strip
```

- **submit** (POST, потребитель): кладёт задачу в `pending/`, long-poll'ит `done/`, отдаёт OpenAI-ответ. 504 если воркер не успел за 25с.
- **claim** (GET, воркер): атомарно (rename) забирает старейшую задачу `pending/→working/`. 204 если пусто.
- **complete** (POST `&id=`, воркер): кладёт результат в `done/`.
- **stats** (GET): `{"pending","working","done"}` — для проверки живости/писчей директории.
- Защита: токен (`?token=` / `Authorization: Bearer` / `X-Auth-Token`, `hash_equals`).

## Где что лежит

**Код** (репозиторий miner-dash, `deploy/tg-proxy/`):
- `llmq.php` — очередь (кладётся на forgetborders docroot).
- `llm_worker.php` — воркер (кладётся на риг).
- `llm.php` — прямой OpenAI→ollama шим (используется, только если риг доступен по HTTP напрямую; в relay-схеме НЕ задействован).
- `tg.php` — прокси t.me/openrouter (отдельная тема).
- `README.md` — деплой-инструкции.

**forgetborders.com** (shared, только PHP, без SSH — заливает владелец вручную):
- `llmq.php` в docroot. Токен — в `$FALLBACK_TOKEN` внутри файла (env `TG_PROXY_TOKEN`
  на этом хостинге не пробрасывается). Очередь: `sys_get_temp_dir()/llmq` (писчая; если
  нет — задать `$QUEUE_DIR`). Требует конкурентные PHP-запросы (Apache/fpm — ок) и
  `max_execution_time ≥ 30с`.

**Риг** (`ssh llm`, systemd **--user**, нужен `export XDG_RUNTIME_DIR=/run/user/$(id -u)`):
- `~/llm-shim/llm_worker.php`.
- Сервис `llm-worker.service` (enabled, linger включён — переживает логаут). Env в юните:
  `LLMQ_URL=https://forgetborders.com/llmq.php`, `TG_PROXY_TOKEN=…`,
  `OLLAMA_URL=http://127.0.0.1:11434/api/chat`, `OLLAMA_MODEL=gemma4:26b`, `IDLE_SLEEP_MS=1500`.
- Управление: `systemctl --user status|restart|stop llm-worker`, логи `journalctl --user -u llm-worker`.
- Служебное: `~/bin/cloudflared` установлен, но НЕ используется (туннель не живёт); юниты `cf-quick`, `llm-shim` — отключены.

## Важные квирки gemma4:26b (учтено в воркере)

- **Thinking-модель.** Через OpenAI `/v1/chat/completions` `content` приходит ПУСТОЙ
  (рассуждение съедает `max_tokens`). Обязательно нативный `/api/chat` с `"think":false`.
- Оборачивает JSON в ```` ```json ````-фенсы — воркер снимает перед отдачей.
- На задаче извлечения прайс-строк: 24/24 цены верно, 22/24 model_id (проверено).

## Как подключить новый проект-потребитель (пример: miner-dash)

Код-потребитель шлёт обычный OpenAI-запрос, меняется только URL:
```dotenv
LLM_API_URL="https://forgetborders.com/llmq.php?action=submit&token=afbef1952cdeaa95d3756aa8d09af86f2b68dbcc15c0e971"
LLM_MODEL=gemma4:26b
```
Клиентский таймаут в коде поднять до ≥40с. Всё остальное (think:false, fence-strip,
формат) прячет relay.

## Диагностика

- `curl "https://forgetborders.com/llmq.php?action=stats&token=<TOKEN>"` → живость очереди.
- `curl -X POST ".../llmq.php?action=submit&token=<TOKEN>" -d '{"model":"gemma4:26b","max_tokens":16,"messages":[{"role":"user","content":"ping, reply {\"ok\":1}"}]}'` → сквозной тест.
- Воркер молчит / `claim http 500` первые секунды после деплоя — транзиент (создание директорий), проходит.
- IP рига скачет (DHCP) — воркер ходит на forgetborders (стабильный домен), так что смена IP рига relay НЕ ломает. Ломается только прямой доступ из LAN (`llm_infra_handoff.md` §1).

## Ограничения

- Один воркер, GPU concurrency ≈ 1 → пропускная способность ~1 запрос за раз (~5с). Для
  батчей — сериализовать; несколько проектов делят один риг.
- Риг должен быть включён и с интернетом; ollama держит `gemma4:26b` в VRAM (keep-alive Forever).
- Токен общий — при ротации менять в трёх местах: `llmq.php` ($FALLBACK_TOKEN на forgetborders),
  юнит `llm-worker` на риге, `LLM_API_URL` у каждого потребителя.

## Для нового разработчика в команде (не в LAN)

> Практическая памятка, если ты подключаешься к WEARBASE со своей машины и **не** в
> домашней сети рига. Сначала общий онбординг — [`onboarding.md`](onboarding.md).

**Главное: для обычной разработки каталога / фронта / бэка LLM не нужен вообще.**
Подними локальный MySQL, импортируй дамп (`onboarding.md` → раздел про дамп) — весь
каталог, контент, атрибуты, ключевики уже сгенерированы и лежат в данных. Ты работаешь
с готовым контентом; генерацию нового гоняет владелец на риге. Этот раздел — только если
тебе досталась задача, которая **реально дёргает генерацию** (RAG / контент брендов).

**Почему нельзя просто вписать relay в `LOCAL_LLM_URL` (важный подвох).**
Форматы несовместимы:
- этот relay говорит в **OpenAI-формате** — принимает `{messages, max_tokens}`, отдаёт
  `{"choices":[{"message":{"content":…}}]}`;
- а WEARBASE почти всю генерацию гонит через **local-путь** `LlmService` (`local: true`),
  который шлёт **ollama-нативный** `/api/chat` (`{think:false, options:{…}}`) и читает
  ответ из `message.content`.

Если подставить URL relay в `LOCAL_LLM_URL`, local-путь получит `choices[]` вместо
`message.content` → вернётся **пустая строка**, а не ошибка (тихо сломается). Relay —
drop-in только для **remote/OpenAI**-эндпоинта (`generateRemote`, читает `choices[]`),
а не для local-`/api/chat`.

**Что делать, если LLM всё же нужен (по возрастанию усилий):**
1. **Ничего.** Тестируй логику на готовом контенте из дампа. За перегенерацией — к владельцу
   (он на риге, latency/GPU там). Это правильный дефолт.
2. Свой **облачный OpenRouter**: впиши `OPENROUTER_API_KEY` + `OPENROUTER_MODEL` в `.env.local`.
   Покроет только **remote**-вызовы `LlmService`. ⚠️ методы content-gen жёстко зашиты
   `local: true` (см. массу вызовов в `src/Service/LlmService.php`) — их это НЕ переключит.
3. Использовать relay для local-пути — потребует адаптера-шима OpenAI↔ollama-native
   (переписать payload и распарсить `choices[]` → отдать как `message.content`). Не сделано;
   заводить только если задача того стоит.

**Итог для онбординга:** пропусти LLM-инфру, работай на данных из дампа. Relay актуален,
только если сознательно интегрируешь remote-путь или пишешь шим.
