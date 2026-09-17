# Подготовка фото вещей для ежедневных коллажей

Прод хранит исходные снимки в приватном хранилище. Агент на LLM-сервере получает
очередь через `GET /api/v1/wardrobe/daily/images/queue?after=0`, скачивает снимок
через существующую защищённую ручку `/api/v1/wardrobe/daily/prepare/photo/{id}`,
удаляет фон нодой ComfyUI `BiRefNetRMBG` и отправляет PNG как тело
`POST /api/v1/wardrobe/daily/images/result/{id}`. Подпись тела —
`X-Signature: HMAC-SHA256(PNG, AGENT_API_SECRET)`; исходная версия не меняется.

PNG хранится на проде в `var/uploads/wardrobe_prepared` с ревизией исходного фото
в имени. Изображения не доступны через публичный URL. В очередь попадают только
вещи владельцев с действующими согласиями на обработку фото и персонализацию.
При смене обложки новая ревизия вновь попадает в очередь. Новые ежедневные
коллажи используют подготовленное фото, если оно уже доставлено; старые коллажи
остаются прежними.

На LLM-сервере нужны Python 3, запущенный ComfyUI с моделью `BiRefNet-general`
и переменные `PROD_API_URL`, `AGENT_API_TOKEN`, `AGENT_API_SECRET`.
`COMFYUI_URL` по умолчанию `http://127.0.0.1:8188`.

```bash
python3 /path/to/tools/wardrobe-item-cutouts/worker.py
```

Запускайте cron до ночной генерации образов в 05:00, например в 03:00:

```cron
0 3 * * * . /path/to/wardrobe-cutouts.env; /usr/bin/python3 /path/to/tools/wardrobe-item-cutouts/worker.py >> /path/to/wardrobe-cutouts.log 2>&1
```

Повторный запуск безопасен: готовые ревизии не возвращаются в очередь. Неудачные
вещи попадут в неё снова при следующем запуске.
