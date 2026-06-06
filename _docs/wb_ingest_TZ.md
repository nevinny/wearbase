# ТЗ: Ингест товаров с Wildberries → корпус брендов (для исполнителя)

> Писано максимально явно и пошагово. НЕ импровизируй. Делай ровно по шагам. Где сказано «через прокси» — значит ВСЕГДА через прокси. Где числа — бери эти числа.

## 0. Цель (одно предложение)
Для каждого бренда WEARBASE достать его реальные товары с Wildberries (название, категория, состав, характеристики), сложить их как текстовый документ в `brand_source_document`, затем переэмбеддить и перегенерировать описание — чтобы брендам с «тонким» сайтом появился grounded-контент, и чтобы появился продуктовый корпус под стиле-разметку и капсулы.

## 1. Контекст (что уже есть, не ломать)
- Symfony 7.3, PHP 8.2. Команды в `src/Command/`, сервисы в `src/Service/`.
- БД: таблицы `brand`, `brand_source_document`, `brand_rag_pipeline`, `brand_attribute`.
- Уже есть: `app:brand:embed --id=X`, `app:brand:generate-content --id=X --grounded-only`, `app:brand:extract` (атрибуты/стили). Их ПЕРЕИСПОЛЬЗУЙ, не переписывай.
- Локальная ollama для эмбеддингов/генерации на 192.168.2.43 (env `LOCAL_EMBED_URL`, `LOCAL_LLM_URL`).

## 2. ГЛАВНОЕ ПРО АНТИ-БОТ (не нарушай, иначе забанишь IP)
- **WB банит прямые частые запросы** → HTTP 429. С обычного IP сервера прямые запросы НЕ работают.
- **ВСЕ запросы к WB делать ТОЛЬКО через SOCKS5-прокси** `socks5://172.17.0.1:1080` (мобильный egress, туннель уже поднят на сервере .43 как systemd-сервис `winproxy-tunnel`). В Symfony HttpClient: опция `'proxy' => 'socks5://172.17.0.1:1080'` ИЛИ для curl `--socks5 172.17.0.1:1080`.
- **Пейсинг: пауза ≥1 секунда между запросами к WB.** Не параллель — последовательно.
- **Backoff на 429:** если пришёл 429 — пауза 30с, повтор; до 3 попыток; если все 3 — пометить бренд `wb_status='error'` и идти дальше (не зацикливаться).
- Юзер-агент в запросах: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125 Safari/537.36`.

## 3. WB API — ТОЧНЫЕ эндпоинты и как парсить
### 3.1 Поиск товаров бренда (шаг 1)
GET (через прокси):
```
https://search.wb.ru/exactmatch/ru/common/v5/search?appType=1&curr=rub&dest=-1257786&query=<БРЕНД_URLENCODED>&resultset=catalog&sort=popular&spp=30
```
Ответ — JSON. Два случая:
- **Случай A (есть товары сразу):** `data.products` — массив. Каждый элемент: `{id (=nm), brand, name, ...}`. Используй его.
- **Случай B (точный бренд):** `data.products` пустой ИЛИ `metadata.context = ["brand"]` и `metadata.catalog_value = "preset=<ЧИСЛО>"`. Тогда товары надо брать отдельным запросом каталога бренда (шаг 1b).

### 3.2 (1b) Если вернулся preset/brand — взять каталог бренда
Попробуй по порядку, бери первый, что вернёт `data.products` непустым (через прокси, с паузой):
```
https://catalog.wb.ru/brands/v2/catalog?appType=1&curr=rub&dest=-1257786&brand=<brandId>&sort=popular&spp=30
```
(brandId ищи в ответе поиска — поле `brandId`/`id` в metadata, либо распарси из `preset=`.)
⚠️ Эндпоинты WB периодически меняются. Если оба (`search` и `brands/v2/catalog`) пустые — залогируй сырой ответ в `var/log/wb-debug.log` и пометь бренд `wb_status='no_products'`. НЕ выдумывай данные.

### 3.3 (опционально, v2) Детальная карточка товара — состав/описание
Для nm (id товара) basket-host выводится из nm. Алгоритм:
```
vol  = nm // 100000
part = nm // 1000
URL  = https://basket-{HOST}.wbbasket.ru/vol{vol}/part{part}/{nm}/info/ru/card.json
```
HOST (число с ведущим нулём, 01..30) подбирается по диапазону vol — диапазоны ПЛАВАЮТ, поэтому: перебирай HOST от 01 до 30, бери первый, что вернёт валидный JSON (через прокси, пауза). В card.json: `imt_name` (название), `description` (описание), `subj_name`/`subj_root_name` (категория), `options`/`grouped_options` (характеристики, в т.ч. «Состав»).
**MVP: card.json МОЖНО пропустить** — названий+категорий из поиска уже хватает для grounded-описания. Делай card.json только если останется время.

## 4. Брэнд-матчинг (отсечь чужие товары)
В выдаче поиска бывают товары ДРУГИХ брендов. Бери только те, где `product.brand` (нормализованное: trim, lower) совпадает с названием бренда WEARBASE (нормализованное). Если совпадений 0 — `wb_status='no_products'`.

## 5. Что собрать в документ (текст для корпуса)
Сформируй один русский текст по шаблону (без выдумок, только из данных WB):
```
Бренд <Title> на Wildberries. Ассортимент (<N> товаров): <name1>; <name2>; ... .
Категории: <уникальные subj_name через запятую>.
[если есть card.json] Состав/материалы: <состав по товарам>.
```
Не добавляй цены, историю, город — только то, что реально пришло.

## 6. Хранение (точно)
Вставь/обнови строку в `brand_source_document`:
- `brand_id` = id бренда
- `source_type` = `'marketplace'`
- `url` = `'wb:'<slug>` (служебный идентификатор источника)
- `text` = собранный текст из §5
- остальные поля как у существующих документов (created_at и т.п.)
Дедуп: если для бренда уже есть документ с `url='wb:'<slug>` — обнови его (delete-and-replace), не плоди дубли.
Заведи в `brand_rag_pipeline` поле статуса ингеста: `wb_status ENUM(NULL|done|no_products|error)` + `wb_checked_at DATETIME` (миграция `CREATE ... IF NOT EXISTS`/`ALTER ... ADD`). Финдер берёт бренды с `wb_status IS NULL`.

## 7. После ингеста — переэмбеддить и перегенерить
Для каждого бренда, у кого появился WB-документ:
1. `php bin/console app:brand:embed --id=<ID> --no-debug` (переэмбеддит корпус, включая WB-док).
2. `php bin/console app:brand:generate-content --id=<ID> --grounded-only --no-debug` (если gate пройдёт — сгенерит/обновит описание; снимет deferred).
Запускать на сервере 192.168.2.43 (там ollama). Не на проде.

## 8. Что создать (код)
- **Сервис** `src/Service/WildberriesClient.php`: методы `searchBrandProducts(string $brand): array` (шаги 3.1–3.2, через прокси, пейсинг, backoff), опц. `productCard(int $nm): ?array` (3.3). HttpClient с `'proxy' => 'socks5://172.17.0.1:1080'`, timeout 20с.
- **Команда** `app:brand:wb-enrich` по образцу `src/Command/EnrichBrandContactsCommand.php` (флаги `--id`, `--limit`, `--shard`, `--total`, `--dry-run`; EM-гигиена: `find`/`flush`/`clear` per brand; пауза между брендами ≥2с). Логика: финдер `wb_status IS NULL` → для каждого: searchBrandProducts → match → собрать текст → сохранить документ → `wb_status='done'`; запустить embed+generate для него ИЛИ оставить демону.
- **Миграция** `Version<дата>_brand_wb_status.php`: `ALTER TABLE brand_rag_pipeline ADD wb_status VARCHAR(12) DEFAULT NULL, ADD wb_checked_at DATETIME DEFAULT NULL`.
- Репозиторий: метод-финдер `findForWbEnrich(limit, shard, total)` по образцу `findForCrawl` в `BrandRepository.php`.

## 9. Псевдокод команды (ровно так)
```
brands = repo.findForWbEnrich(limit, shard, total)   // wb_status IS NULL, status active/new
foreach brand:
    try:
        prods = wb.searchBrandProducts(brand.title)        // через прокси, пауза 1с, backoff 429
        prods = [p for p in prods if norm(p.brand)==norm(brand.title)]
        if prods empty: set wb_status='no_products'; continue
        text = build_text(brand, prods)                    // §5
        upsert brand_source_document(brand, 'marketplace', 'wb:'+slug, text)
        set wb_status='done', wb_checked_at=now
    catch 429-after-3-retries or error:
        set wb_status='error'
    em.flush(); em.clear()
    sleep(2)                                                // между брендами
```

## 10. Критерии приёмки
- [ ] `app:brand:wb-enrich --id=6571 --dry-run` показывает найденные товары moncecy (12 шт.: брюки палаццо, платья мини, свитшоты, шорты), НЕ сохраняя.
- [ ] Без dry-run: появляется `brand_source_document` (source_type='marketplace') для бренда; `wb_status='done'`.
- [ ] Чужие бренды в выдаче отфильтрованы (в документе только товары этого бренда).
- [ ] Все запросы к WB идут через прокси (проверь: SELECT не должно быть 429 в логах; `var/log/wb-debug.log` чист).
- [ ] После embed+generate бренд с WB-корпусом выходит из deferred и получает grounded-описание.
- [ ] Прогон 50 брендов: нет 429-банов (пейсинг работает), нет дублей документов.

## 11. GOTCHAS (явно, не наступи)
1. **Прямые запросы к WB = 429.** ТОЛЬКО через прокси socks5://172.17.0.1:1080. Если прокси не отвечает — `systemctl status winproxy-tunnel` на .43.
2. **Не параллель.** Последовательно, пауза ≥1с между WB-запросами, ≥2с между брендами. Параллель = бан.
3. **WB-эндпоинты плавают.** Если пусто — логируй сырой ответ, ставь `no_products`, НЕ выдумывай товары.
4. **Брэнд-матчинг обязателен** — иначе зальёшь чужие товары в корпус бренда.
5. **Не трогай прод.** embed/generate — на 192.168.2.43, документы — в общую БД (MySQL на Mac 192.168.2.115). На wearbase.ru попадёт потом штатным push-демоном.
6. **basket-host для card.json плавает** — перебирай 01..30, бери первый валидный; card.json вообще опционален для MVP.
7. **Дедуп документов** по `url='wb:'+slug` — обновляй, не плоди.

## 12. Порядок работ
1. Миграция (wb_status).
2. WildberriesClient (поиск через прокси + match) — проверь на moncecy (`--id=6571 --dry-run`).
3. Команда wb-enrich + финдер.
4. Реальный прогон --id=6571 → документ → embed → generate → проверить grounded-описание.
5. Батч --limit=50 (проверить отсутствие банов), потом по всем (--shard/--total если параллелить ПРОЦЕССАМИ, но каждый процесс всё равно через тот же прокси с пейсингом).
6. (v2) card.json для состава; интеграция в app:brand:extract для стиле-разметки по товарам.

*Эталон рабочего факта (проверено 06.06.2026): WB-поиск по «moncecy» через мобильный прокси вернул 12 товаров (женская одежда). Прямой IP сервера на тот же запрос — HTTP 429.*
