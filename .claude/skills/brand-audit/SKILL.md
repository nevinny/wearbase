---
name: brand-audit
description: Проверка полноты бренда/брендов — контакты, атрибуты, ключевики, стадия конвейера — и точечное дообогащение. Use when «у бренда нет контактов/атрибутов», ссылка wearbase.ru/ru/brands/<slug> с претензией, «проверь бренд X», «найди опубликованные без контактов», «проверь на N случайных брендах».
model: sonnet
---

`PHP=/opt/homebrew/bin/php`. ⚠️ `dbal:run-sql`: SQL строго ОДНОЙ строкой; «0 rows affected» на SELECT — артефакт раннера, не пустой результат. Slug берётся из урла `wearbase.ru/ru/brands/<slug>`.

## Точечная проверка бренда (по slug/id)

Локальная БД (Mac) — источник контента; прод получает копию через push. Независимые запросы — параллельно:

1. `php bin/console dbal:run-sql "SELECT id, slug, status, published_at, email, phone, contact_status, contact_enriched_at FROM brand WHERE slug='<slug>'" --no-debug`
2. `php bin/console dbal:run-sql "SELECT link_type, link_url FROM brand_link WHERE brand_id=<id>" --no-debug` — сайт = `link_type='website'`
3. `php bin/console dbal:run-sql "SELECT name, value, provenance FROM brand_attribute WHERE brand_id=<id>" --no-debug`
4. `php bin/console dbal:run-sql "SELECT COUNT(*) c FROM brand_keyword WHERE brand_id=<id>" --no-debug`
5. `php bin/console dbal:run-sql "SELECT status, attributes_status, keywords_status, faq_status, pushed_at, push_error FROM brand_rag_pipeline WHERE brand_id=<id>" --no-debug`

Если локально всё заполнено, а на сайте пусто — сверь прод тем же SQL: `ssh regru 'cd wearbase.ru && php bin/console dbal:run-sql "..." --no-debug'` (расхождение = бренд не допушен, см. «Дообогащение» шаг 3).

## Массовые проверки (опубликованные бренды)

- **Без контактов** (ни email, ни сайта): `php bin/console dbal:run-sql "SELECT b.id, b.slug FROM brand b WHERE b.status='active' AND b.published_at IS NOT NULL AND (b.email IS NULL OR b.email='') AND NOT EXISTS (SELECT 1 FROM brand_link l WHERE l.brand_id=b.id AND l.link_type='website') ORDER BY b.published_at DESC" --no-debug`
- **Без атрибутов**: `... FROM brand b WHERE b.status='active' AND b.published_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM brand_attribute a WHERE a.brand_id=b.id) ...`
- **N случайных** для выборочной проверки: добавь `ORDER BY RAND() LIMIT <N>` и прогони точечную проверку по каждому.

## Дообогащение (только по явной просьбе)

1. Контакты: `php bin/console app:brand:enrich-contacts --id=<id> --no-debug` (perplexity/sonar, нужен `OPENROUTER_API_KEY`; `--force` — пересобрать, `--no-verify` — без HTTP-проверки URL).
2. Атрибуты: `php bin/console app:brand:extract --id=<id> --no-debug` (точечный набор: `--ids=1,2,3`; `--published-missing` — только опубликованные с пустыми city/country/год; `--push` — сразу доставить на прод).
3. Доставка на прод (если не использовал `--push`): `php bin/console app:brand:push --id=<id> --no-debug` (приоритетная публикация мимо дрипа: добавь `--publish`).
4. Перепроверь точечной проверкой + открой `https://wearbase.ru/ru/brands/<slug>`.

Ответ — ОДНИМ сообщением: сначала вердикт (чего не хватает / всё на месте), потом факты. Массовые прогоны >50 брендов — `php -d memory_limit=512M`.
