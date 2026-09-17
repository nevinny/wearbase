# Подготовка изображений вещей

Продакшен хранит исходное фото и отдельную подготовленную PNG-версию. Защищённый
`GET /api/v1/wardrobe/outfit-images?after=0` выдаёт вещи, у которых нет актуальной
версии. LLM-сервер скачивает фото, удаляет фон через ComfyUI `BiRefNetRMBG` и
возвращает PNG через подписанный `POST /api/v1/wardrobe/outfit-images/{id}`.
Исходное фото не заменяется. При его смене подготовленная версия сбрасывается.

На проде выполнить миграцию `Version20260917_wardrobe_outfit_images` и задать
`AGENT_API_TOKEN`, `AGENT_API_SECRET`, `SITE_BASE_URL` (те же агентские ключи,
которые использует API брендов). На LLM-сервере должны быть доступны ComfyUI с
нодой `BiRefNetRMBG` и моделью `BiRefNet-general`. Серверу нужен доступ к
`SITE_BASE_URL` по HTTPS.

На LLM-сервере задать `WEARBASE_URL`, `AGENT_API_TOKEN`, `AGENT_API_SECRET` и
необязательный `COMFYUI_URL` (по умолчанию `http://127.0.0.1:8188`). Запуск:

```bash
python3 /path/to/worker.py
```

Пример cron (каждый день в 03:00, окружение загружается из приватного файла):

```cron
0 3 * * * . /path/to/wardrobe-outfit-images.env; /usr/bin/python3 /path/to/worker.py >> /path/to/wardrobe-outfit-images.log 2>&1
```

Повторный запуск безопасен: прод выдаёт только новые или изменённые фотографии.
Если фото изменится между скачиванием и возвратом результата, прод ответит 409;
следующий запуск обработает новую версию.
