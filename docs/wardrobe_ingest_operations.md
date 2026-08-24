# Авто-ингест гардероба: операционная проверка

Очередь фото проверяется без запуска vision-модели:

```bash
php bin/console app:wardrobe:ingest-health
php bin/console app:wardrobe:ingest-health --json
php bin/console app:wardrobe:ingest-health --check
```

`--check` возвращает ненулевой код только при `critical`: каталог приватных загрузок отсутствует
или недоступен для записи либо последний запуск scheduler завершился с ошибкой. Наличие retry,
failed или истёкших lease отображается как `warning`: истёкший lease штатно забирается следующим
worker-проходом, поэтому сам по себе не означает аварию.

Команда показывает:

- число pending и возраст самого старого pending;
- истёкшие processing lease;
- failed и pending, уже возвращённые на retry;
- объём файлов по сохранённому `file_size`, доступность каталога для записи и свободное место;
- состояние строки `scheduled_command`, время и exit code последнего запуска worker.

## Production smoke

После деплоя и миграций:

```bash
APP_ENV=prod php bin/console app:wardrobe:ingest-health --check --no-debug
APP_ENV=prod php bin/console app:wardrobe:ingest-health --json --no-debug
```

Проверить, что `storage_writable=true`, `scheduler_configured=true`, последний exit code равен `0`,
а возраст oldest pending уменьшается после очередного двухминутного запуска. При expired lease
повторить команду после следующего тика: счётчик должен обнулиться или запись должна перейти в retry/failed.

## Ограничение данных scheduler

`scheduled_command` хранит только последний запуск. Поэтому `scheduler_last_success_at` достоверен,
только если последний exit code равен `0`. После неуспешного запуска прежнее время успеха восстановить
невозможно: команда возвращает `scheduler_last_success_known=false`, не подставляя вымышленное значение.
Исторический last-success потребует отдельного журнала запусков и не входит в этот атомарный блок.
