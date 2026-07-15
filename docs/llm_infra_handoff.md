# LLM-инфраструктура — handoff

> Единый локальный AI-сервер (ollama + Qdrant + SearXNG) на майнинг-риге. Этот документ —
> для переноса в другой проект, который будет собирать **свои коллекции на той же инфре**.
> Снято вживую 2026-07-15. Все сервисы слушают `0.0.0.0` → доступны из LAN.

## 1. Доступ

- **SSH:** алиас `ssh llm` (сейчас `192.168.0.111`).
- ⚠️ **IP непостоянен** (DHCP/переезды сети — был `.2.43`, `.0.119`, сейчас `.0.111`). При
  недоступности: сканировать подсеть на порт `11434`, обновить `~/.ssh/config` + все env-URL.
  «Host key verification failed» после смены IP → `ssh-keygen -R <ip>`.
- Только LAN. Наружу (прод в облаке и т.п.) риг **не виден** — если потребитель работает вне
  LAN, нужен туннель (Tailscale / CF-proxy). Для проектов на этой же машине/LAN — прямой доступ.

## 2. Железо (и его пределы)

| Ресурс | Что | Замечание |
|---|---|---|
| GPU | 3× RTX 2060 SUPER (8 GB) + 1× RTX A4000 (16 GB) ≈ **40 GB VRAM** | большие модели дробятся по картам |
| CPU | Intel i7-2670QM, 4C/8T @ 2.2 GHz | старый (Sandy Bridge), CPU-инференс медленный |
| **RAM** | **7.6 GiB всего** (~3 GiB свободно) | 🔴 **главный потолок** — модели, спиллящие в RAM, не влезают |
| Диск | 218 GB, ~110 GB свободно (48%) | перед `ollama pull` проверять `df -h` |

**Практический вывод:** VRAM хватает на модель ~26 GB, но **системная RAM = 7.6 GB — узкое
горлышко**. Перед скачиванием новой модели: `ssh llm 'free -h; df -h /'`. Модель ~31 GB
(gemma4:31b) на этой машине не запускается — упирается в RAM.

## 3. Сервисы и порты

| Порт | Сервис | Управление | Назначение |
|---|---|---|---|
| `11434` | **ollama** | systemd `ollama.service` | генерация + vision + эмбеддинги |
| `6333` | **Qdrant** v1.18.1 | бинарь `./qdrant` (не docker) | векторное хранилище |
| `8080` | **SearXNG** | egress через `winproxy-tunnel.service` (SOCKS5 поверх Windows-мобильного канала) | метапоиск для discover/скрейпа |
| `8088` | дашборд майнинга | — | не относится к AI |

⚠️ SearXNG-egress зависит от `winproxy-tunnel.service` — может лежать независимо от SearXNG.

## 4. Модели ollama

Список: `ssh llm 'ollama list'`. Загруженное сейчас: `ssh llm 'ollama ps'`.

| Модель | Роль | Примечание |
|---|---|---|
| **`gemma4:26b`** | генерация текста (осн.) | 26 GB, **vision-capable** (подтверждено), ctx 32k, сейчас keep-alive Forever |
| `qwen3.5:27b`, `qwen3.6:27b` | альт. генерация | тоже `vision` в capabilities |
| **`qwen3-embedding:0.6b`** | эмбеддинги | 639 MB, **выход 1024-dim** |

Проверить capability: `ssh llm 'ollama show <model>'` (секция Capabilities: completion/vision/tools/thinking).

### Эндпоинты (HTTP, все на `http://<ip>:11434`)

**Генерация** — `POST /api/chat`:
```json
{"model":"gemma4:26b","messages":[{"role":"user","content":"..."}],"stream":false,"think":false,
 "options":{"temperature":0.4},"keep_alive":"30m"}
```
Vision — добавить в message: `"images":["<base64-без-префикса>"]` (ollama, не data-URL).
Ответ: `.message.content`.

**Эмбеддинги** — `POST /api/embed`:
```json
{"model":"qwen3-embedding:0.6b","input":["текст1","текст2"],"keep_alive":"30m"}
```
Ответ: `.embeddings` — массив векторов по 1024 float. `input` принимает строку или массив (батч).

## 5. Qdrant: существующие коллекции

- URL: `http://<ip>:6333` · **api-key обязателен** (header `api-key: <KEY>`).
- Ключ (shared secret этой инфры): `4da86c7a943d07a888a8c86920579b2a5a8129b185c52a55`.
- **Все коллекции: 1024-dim, distance `Cosine`** (совпадает с выходом эмбеддера).

| Коллекция | Точек | Payload-поля | Чей проект |
|---|---|---|---|
| `brand_chunks` | 191 477 | `brand_id, doc_id, chunk_index, source_url, source_type, relevance, text` | WEARBASE (RAG брендов) |
| `topic_chunks` | 35 469 | `channel, video_id, role, chunk_index, text` | YT-база бизнес-советника |
| `seo_factory_chunks` | 319 | `site_id, item_id, text, source_url, source_type, relevance` | seo-factory |
| `borderless` | 0 | (пустая, заготовка) | foreign-витрина |

Список/детали:
```bash
KEY=4da86c7a943d07a888a8c86920579b2a5a8129b185c52a55
curl -s -H "api-key: $KEY" http://<ip>:6333/collections
curl -s -H "api-key: $KEY" http://<ip>:6333/collections/brand_chunks   # config + points_count
```

## 6. Как завести НОВУЮ коллекцию (конвенции этой инфры)

Держитесь общих правил, чтобы не конфликтовать с существующими коллекциями:

1. **Имя:** `<домен>_chunks` (напр. `myproject_chunks`). Пространство имён общее — не занимайте
   чужие имена из §5.
2. **Вектор:** `size: 1024`, `distance: Cosine` — обязательно, т.к. эмбеддер один на всех
   (qwen3-embedding:0.6b). Свою размерность завести нельзя без другой модели.
3. **ID точек:** детерминированный **UUIDv5** от стабильного ключа (напр. `doc_id:chunk_index`) —
   даёт идемпотентный upsert (повторная заливка не плодит дубли).
4. **Payload:** всегда кладите `text` (сам чанк) + метаданные для фильтрации — id сущности,
   `source_url`, `source_type`, `relevance`, `chunk_index`. Фильтры Qdrant работают по payload.

```bash
# создать коллекцию
curl -s -X PUT -H "api-key: $KEY" -H 'Content-Type: application/json' \
  http://<ip>:6333/collections/myproject_chunks \
  -d '{"vectors":{"size":1024,"distance":"Cosine"}}'

# 1) эмбеддинг чанка (ollama) -> 2) upsert точки
VEC=$(curl -s http://<ip>:11434/api/embed -d '{"model":"qwen3-embedding:0.6b","input":"мой чанк"}' \
      | python3 -c 'import sys,json;print(json.dumps(json.load(sys.stdin)["embeddings"][0]))')
curl -s -X PUT -H "api-key: $KEY" -H 'Content-Type: application/json' \
  http://<ip>:6333/collections/myproject_chunks/points \
  -d "{\"points\":[{\"id\":\"<uuid-v5>\",\"vector\":$VEC,\"payload\":{\"text\":\"мой чанк\",\"item_id\":42}}]}"

# поиск (top-k) — вектор запроса тем же эмбеддером
curl -s -X POST -H "api-key: $KEY" -H 'Content-Type: application/json' \
  http://<ip>:6333/collections/myproject_chunks/points/search \
  -d "{\"vector\":$QVEC,\"limit\":5,\"with_payload\":true}"
```

## 7. Грабли (обязательно к прочтению перед нагрузкой)

- 🔴 **Одна очередь на GPU (concurrency≈1).** Параллельные тяжёлые потребители генерации роняют
  gemma (переподписка). **Сериализуйте генерацию** между проектами; эмбеддинги лёгкие, но тоже
  делят GPU. Если запускаете батч — не гоняйте одновременно с чужим батчем на этой же машине.
- 🔴 **RAM 7.6 GB — потолок.** Не тяните модели, которым нужен RAM-offload. Проверяйте `free -h`.
- **keep_alive.** Чередование эмбеддер↔генератор вызывает load/unload-трэшинг. Держите нужную
  модель тёплой параметром `keep_alive` (`"30m"` и т.п.), иначе первый запрос после простоя
  ждёт загрузку модели в VRAM.
- **IP-нестабильность** (§1) — не хардкодьте IP в разных местах, держите одну точку конфига.
- **Qdrant без api-key отдаёт 403** на `/collections/*` (корень `/` открыт — так и проверяют, что
  живой). Ключ — в env потребителя.
- **SearXNG** может молчать, если упал `winproxy-tunnel.service`, — это не поломка самого SearXNG.

## 8. Env для потребителя (шаблон)

```dotenv
LOCAL_LLM_URL=http://<ip>:11434/api/chat
LOCAL_LLM_MODEL=gemma4:26b
LOCAL_EMBED_URL=http://<ip>:11434/api/embed
LOCAL_EMBED_MODEL=qwen3-embedding:0.6b
QDRANT_URL=http://<ip>:6333
QDRANT_API_KEY=4da86c7a943d07a888a8c86920579b2a5a8129b185c52a55
QDRANT_COLLECTION=myproject_chunks
SEARXNG_URL=http://<ip>:8080
```

Референс-реализация клиентов (Symfony/PHP) в WEARBASE: `src/Service/EmbeddingService.php`
(вызов `/api/embed`), `src/Service/VectorStoreService.php` (Qdrant upsert/search, UUIDv5 ID),
`src/Service/LlmService.php` (`/api/chat`, текст + `generateVision`).
