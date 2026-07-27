---
name: add-brand
description: Завести новый бренд по имени и провести до публикации - полный конвейер (discover → fetch → embed → keywords → generate → faq → атрибуты → контакты → лого) + форс-пуш и приоритетная публикация на прод. Use when «добавь бренд <имя>», «заведи и опубликуй бренд», «прогони бренд по конвейеру и опубликуй».
model: sonnet
---

`PHP=/opt/homebrew/bin/php`; тяжёлые этапы — `php -d memory_limit=512M ... --no-debug`. Весь прогон 5–20 мин: после каждой стадии отписывайся одной строкой (✅ этап / цифры). ⚠️ `dbal:run-sql`: SQL одной строкой; «0 rows affected» на SELECT — артефакт раннера.

## 0. Предпроверки (параллельно)

- Дубль: `php bin/console dbal:run-sql "SELECT id, slug, status FROM brand WHERE name LIKE '%<имя>%'" --no-debug` — если бренд уже есть, не создавай второй: продолжи конвейер с недостающей стадии (см. шаг 3).
- Сервер .43 жив (без него embed/generate не пройдут): `curl -s -m 5 "$(grep '^LOCAL_LLM_URL' .env.local | cut -d= -f2)/api/tags" >/dev/null && echo ok` — если нет, скил `llm-server` и стоп.

## 1. Создание

```sh
T=$(mktemp); printf '%s\n' '<Имя Бренда>' > "$T"
php bin/console app:brand:import-leads "$T" --source=tg --no-debug; rm -f "$T"
```
Создаёт `Brand(status=new)` с уникальным slug (дедуп по имени/слагу встроен). Возьми id и slug:
`php bin/console dbal:run-sql "SELECT id, slug FROM brand ORDER BY id DESC LIMIT 1" --no-debug`

## 2. Ниша-гейт (до любых публикаций)

`php bin/console app:brand:niche-check --id=<id> --no-debug` — если вердикт `off`, **остановись и доложи**: бренд вне ниши, публиковать нельзя (гейт дрипа его тоже не пропустит).

## 3. Конвейер (по порядку, после каждого этапа сверяй статус)

Статус между этапами: `php bin/console dbal:run-sql "SELECT status, attributes_status, keywords_status, faq_status FROM brand_rag_pipeline WHERE brand_id=<id>" --no-debug`. Любой `*_failed` → стоп и доложить причину.

1. `php bin/console app:brand:discover --id=<id> --no-debug` — URL-кандидаты в очередь.
2. `php -d memory_limit=512M bin/console app:brand:fetch --no-debug` — у fetch нет `--id`, он дренит общую очередь (новый бренд там). Если после него pipeline `dead` (0 корпуса) — стоп: предложи вручную добавить URL сайта через админку `/admin/rag/brand/<id>` («Добавить URL» / «Вставить факт-текст») и после этого повторить с этого шага.
3. `php -d memory_limit=512M bin/console app:brand:embed --id=<id> --no-debug`
4. `php bin/console app:brand:keywords --id=<id> --no-debug` (квота Wordstat 100/час — одному бренду хватает)
5. `php -d memory_limit=512M bin/console app:brand:generate-content --id=<id> --no-debug` (retrieval-gate: chunks≥3 и score≥0.5, иначе legacy-генерация — это не ошибка)
6. `php bin/console app:brand:faq --id=<id> --no-debug`
7. `php bin/console app:brand:extract --id=<id> --no-debug` — атрибуты + city/год
8. `php bin/console app:brand:enrich-contacts --id=<id> --no-debug` — контакты (perplexity/sonar)
9. `php bin/console app:brand:logo --id=<id> --no-debug`

## 4. Форс-пуш и публикация (мимо дрипа)

```sh
php bin/console app:brand:push --id=<id> --publish --no-debug
```
Доставка на прод + немедленная публикация (`/api/v1/brands/publish` + IndexNow; входит в дневной таргет ramp'а). Ре-пуш после правок — добавь `--force`. Если push ругается на `isPublishReady` — смотри, какого этапа не хватает (шаг 3), не обходи проверку.

## 5. Верификация и отчёт

- `curl -s -o /dev/null -w '%{http_code}' https://wearbase.ru/ru/brands/<slug>` → ожидаем 200.
- Прод: `ssh regru 'cd wearbase.ru && php bin/console dbal:run-sql "SELECT status, published_at FROM brand WHERE slug='"'"'<slug>'"'"'" --no-debug'`

Финальный отчёт ОДНИМ сообщением: id, slug, ссылка, что заполнено (контакты/атрибуты/ключевики/FAQ/лого — есть или нет), где были подводные камни. Ничего не публиковать, если niche-гейт `off` или контент не сгенерировался.
