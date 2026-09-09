# LLM-инфраструктура — handoff

> Единый локальный AI-сервер (ollama + Qdrant + SearXNG) на майнинг-риге. Этот документ —
> для переноса в другой проект, который будет собирать **свои коллекции на той же инфре**.
> Snapshot снят вживую 2026-07-15. На 2026-08-08 сервер выключен: модели, IP, порты и
> сервисы перепроверить после включения. Все сервисы в snapshot слушали `0.0.0.0`.

## 1. Доступ

- **SSH:** алиас `ssh llm` (сейчас `192.168.2.43`, проверено 2026-09-03).
- ⚠️ **IP непостоянен** (DHCP/переезды сети — был `.0.119`, `.0.111`, сейчас снова `.2.43`). При
  недоступности: сканировать подсеть на порт `11434`, обновить `~/.ssh/config` + все env-URL.
  «Host key verification failed» после смены IP → `ssh-keygen -R <ip>`.
- Только LAN. Для локальной разработки вне LAN используется pull-relay `llmq.php`, описанный в
  [llm_relay_handoff.md](llm_relay_handoff.md). Production получит отдельный endpoint (TBD).

## 2. Железо (и его пределы)

| Ресурс | Что | Замечание |
|---|---|---|
| GPU | 3× RTX 2060 SUPER (8 GB) + 1× RTX A4000 (16 GB) ≈ **40 GB VRAM** | большие модели дробятся по картам |
| CPU | Intel i7-2670QM, 4C/8T @ 2.2 GHz | старый (Sandy Bridge), CPU-инференс медленный |
| **RAM** | **7.6 GiB всего** (~3 GiB свободно) | 🔴 **главный потолок** — модели, спиллящие в RAM, не влезают |
| Диск | 218 GB, ~110 GB свободно (48%) | перед `ollama pull` проверять `df -h` |

### Шум и охлаждение GPU (разобрано 2026-09-03)

Тюнинг карт держит root-юнит `rig-gpu-tune.service` (`enabled`): power-лимиты 2060S 140 W ×3 /
A4000 135 W + демон фан-кривой `~/miner/fanctl.py auto`.

⚠️ **Шум вентиляторов на простое держит сам загруженный драйвер.** Пока модуль `nvidia` в памяти,
он владеет вентиляторами и не опускает их ниже ~42% даже при 32 °C. Тишина возможна только при
полностью выгруженном драйвере — тогда управление возвращается VBIOS-контроллеру карты с рабочим
zero-RPM. Держат драйвер `ollama` (постоянно) и `rig-dashboard` (опрос `nvidia-smi` каждые 30 с).
Ни power-лимиты, ни persistence mode, ни резидентные модели тут не виноваты — проверено по
отдельности. `min` в NVML (41%) — кламп драйвера, а не предел прошивки.

Переключатели: `sudo ~/miner/rig-quiet.sh` (тишина, но LLM/эмбеддинги/дашборд не работают) и
`sudo ~/miner/rig-work.sh` (вернуть всё). В тихом режиме **не вызывать `nvidia-smi`** — любой
вызов грузит драйвер обратно; состояние смотреть через `lsmod | grep nvidia`.

⚠️ **У GPU1 неисправен вентилятор** (подтверждено владельцем). Её 0% в `nvidia-smi` — мёртвый
тахометр, а не zero-RPM, поэтому карту нельзя брать за эталон охлаждения. На простое не греется,
но ollama кладёт на неё модели — под нагрузкой уйдёт в троттлинг. Перешивка VBIOS отклонена
владельцем и к шуму отношения не имела.

**Практический вывод:** VRAM хватает на модель ~26 GB, но **системная RAM = 7.6 GB — узкое
горлышко**. Перед скачиванием новой модели: `ssh llm 'free -h; df -h /'`. Модель ~31 GB
(gemma4:31b) на этой машине не запускается — упирается в RAM.

**Про «добавить диски» (вопрос 2026-08-13, +2×128 GB).** Диск здесь **не** потолок: 110 GB
свободных уже покрывают и LTX-2.3 fp8, и MiniMax H3 INT (~34 GB) с энкодерами. Ещё 256 GB дают
только запас (несколько вариантов моделей рядом, whisper-модели, снапшоты Qdrant, буфер под
рендеры) — **ни один класс моделей этим не разблокируется**. Потолок остаётся RAM: платформа
ноутбучная (Sandy Bridge, обычно 2 слота SODIMM DDR3 → реалистично максимум 16 GB, проверить по
факту платы), а локальный видео-ген хочет 32–64 GB. Swap на новом SSD вместо RAM не считается:
шаг диффузии начнёт тошнить в подкачку, клип уедет в десятки минут. Видео-ген → аренда карты
(см. [`marketing_instagram.md`](marketing_instagram.md) §5).

**Про «переставить на другую плату с 16 GB RAM» (вопрос 2026-08-13).** Что это даёт и не даёт:

| | |
|---|---|
| ✅ откроет | **класс 31b** — `gemma4:31b` упирался в системную RAM (CPU-offload просит 11–16 GB при ~9 доступных, см. [`model-ab-bench.md`](model-ab-bench.md)); плюс запас под батчи без риска OOM |
| ❌ не откроет | **49B** (`Nemotron-49B q4` требует 20.9 GB системной RAM) и **локальный видео-ген** (H3-int8 просит ~19–20 GB VRAM > A4000/16, а LTX-2.3 fp8 на грани 16 GB и хочет RAM-offload) |

Проверить **до** переноса — иначе это шаг назад:
1. **AVX у CPU новой платы.** Апгрейд на i7-2670QM ровно этим и был ценен (разблокировал
   detectron2/DensePose, см. [`virtual-tryon.md`](virtual-tryon.md)). Плата с pre-AVX Celeron/
   Pentium вернёт грабли `Illegal instruction`.
2. **Слоты DIMM и максимум платы.** 4 слота DDR3 → путь до 32 GB (уже интересно); ещё одна
   2-слотовая плата с потолком 16 GB — разовый выигрыш в один класс модели.
3. **PCIe: сколько слотов/райзеров под 4 карты** и ширина линий (майнинг-платы дают x1 — на
   загрузку весов это заметно).
4. **Форм-фактор/БП/крепёж** в существующей раме.

Цена переезда: простой рига (майнинг + RAG-конвейер + Qdrant/SearXNG), переустановка драйверов,
новый IP → обновить `~/.ssh/config` и все env-URL (§1). Перф-замеры после смены платы снова
невалидны — перемерять, как после смены CPU.

## 3. Сервисы и порты

| Порт | Сервис | Управление | Назначение |
|---|---|---|---|
| `11434` | **ollama** | systemd `ollama.service` | генерация + vision + эмбеддинги |
| `6333` | **Qdrant** v1.18.1 | бинарь `./qdrant` (не docker) | векторное хранилище |
| `8080` | **SearXNG** | egress через `winproxy-tunnel.service` (SOCKS5 поверх Windows-мобильного канала) | метапоиск для discover/скрейпа |
| `8088` | дашборд майнинга | — | не относится к AI |

⚠️ SearXNG-egress зависит от `winproxy-tunnel.service` — может лежать независимо от SearXNG.

## 4. Модели ollama

Список после включения: `ssh llm 'ollama list'`; загруженное: `ssh llm 'ollama ps'`.
Таблица ниже — состояние snapshot 2026-07-15, не гарантия текущей установки.

| Модель | Роль | Примечание |
|---|---|---|
| **`gemma4:26b`** | генерация текста (осн.) | 26 GB, vision-capable в snapshot, ctx 32k |
| `qwen3.5:27b`, `qwen3.6:27b` | альт. генерация | тоже `vision` в capabilities |
| **`qwen3-embedding:0.6b`** | эмбеддинги | 639 MB, **выход 1024-dim** |
| `WARDROBE_VISION_MODEL` (TBD) | распознавание вещей | будет поднята на том же сервере после включения |

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

- URL: `http://<ip>:6333` · **api-key обязателен** (header `api-key: <QDRANT_API_KEY>`).
- Ключ хранится только в env/secret store; значение из истории Git подлежит ротации.
- **Все коллекции: 1024-dim, distance `Cosine`** (совпадает с выходом эмбеддера).

| Коллекция | Точек | Payload-поля | Чей проект |
|---|---|---|---|
| `brand_chunks` | 191 477 | `brand_id, doc_id, chunk_index, source_url, source_type, relevance, text` | WEARBASE (RAG брендов) |
| `topic_chunks` | 35 469 | `channel, video_id, role, chunk_index, text` | YT-база бизнес-советника |
| `seo_factory_chunks` | 319 | `site_id, item_id, text, source_url, source_type, relevance` | seo-factory |
| `borderless` | 0 | (пустая, заготовка) | foreign-витрина |

Список/детали:
```bash
KEY=<QDRANT_API_KEY>
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
QDRANT_API_KEY=<QDRANT_API_KEY>
QDRANT_COLLECTION=myproject_chunks
SEARXNG_URL=http://<ip>:8080
```

Референс-реализация клиентов (Symfony/PHP) в WEARBASE: `src/Service/EmbeddingService.php`
(вызов `/api/embed`), `src/Service/VectorStoreService.php` (Qdrant upsert/search, UUIDv5 ID),
`src/Service/LlmService.php` (`/api/chat`, текст + `generateVision`).
