# Авто-ингест гардероба: операционная проверка

Очередь фото проверяется без запуска vision-модели:

```bash
php bin/console app:wardrobe:ingest-health
php bin/console app:wardrobe:ingest-health --json
php bin/console app:wardrobe:ingest-health --check
```

`--check` возвращает ненулевой код при `critical`. Критичны:

- отсутствующая, отключённая или ошибочно привязанная не к `prod` строка ingest scheduler;
- scheduler без единого запуска, последний неуспешный запуск или heartbeat старше 10 минут;
- oldest pending старше 15 минут;
- отсутствующий или недоступный для записи каталог приватных загрузок.

Machine-readable `critical_reasons` содержит стабильные коды причины. Наличие retry, failed или
истёкших lease до нарушения SLA отображается как `warning_reasons`: истёкший lease штатно забирается
следующим worker-проходом, поэтому сам по себе не означает аварию.

Команда показывает:

- число pending и возраст самого старого pending;
- истёкшие processing lease;
- failed и pending, уже возвращённые на retry;
- объём файлов по сохранённому `file_size`, доступность каталога для записи и свободное место;
- состояние строки `scheduled_command`, время и exit code последнего запуска worker.

Отдельная строка `scheduled_command` запускает health check каждые 5 минут. Её последний exit code
и JSON доступны в админке scheduler. Проверка ничего не claim'ит и не вызывает vision/AI.

## Production smoke

После деплоя и миграций:

```bash
APP_ENV=prod php bin/console app:wardrobe:ingest-health --check --no-debug
APP_ENV=prod php bin/console app:wardrobe:ingest-health --json --no-debug
```

Проверить, что `storage_writable=true`, `scheduler_configured=true`, последний exit code равен `0`,
а возраст oldest pending уменьшается после очередного двухминутного запуска. При expired lease
повторить команду после следующего тика: счётчик должен обнулиться или запись должна перейти в retry/failed.

Deploy workflow выполняет тот же `--check --json` после миграций и HTTP smoke. Critical-состояние
останавливает деплой и попадает отдельным шагом `Wardrobe ingest health gate` в уведомление, но сам
gate не запускает ingest worker.

## Ограничение данных scheduler

`scheduled_command` хранит только последний запуск. Поэтому `scheduler_last_success_at` достоверен,
только если последний exit code равен `0`. После неуспешного запуска прежнее время успеха восстановить
невозможно: команда возвращает `scheduler_last_success_known=false`, не подставляя вымышленное значение.
Исторический last-success потребует отдельного журнала запусков и не входит в этот атомарный блок.
