# Сид «Чем заменить ушедших» — методика отбора

Файл: `config/social/departed_brands.yaml`. Собран 2026-07-13 для соц-рубрики
«Чем заменить ушедших» (docs/social_value_plan.md, Фаза 1.1). Обновлять руками
по мере появления новых фактов; при добавлении записи — перепроверять
`alternatives` тем же IN-запросом, что описан ниже (правило проекта:
slug обязан существовать в БД со `status='active'`).

## Источники и как проверялось

1. **Локальный архив KSE leave-russia.org** — `~/seo-archives/leave-russia/`.
   - `fashion_brands.tsv` (142 строки, отфильтрованный срез "Consumer Goods &
     Clothing"/"Fashion & Leisure"/"Sport") — статус ухода (`Stay`/`Leave`/`Exited`)
     и `status_long` (`Suspension`/`Withdrawal`/`Exit Completed`/`Scaling Back`).
   - `pages/inditex.html` — детальный текст с фактом ребрендов: **Zara→MAAG**,
     **Bershka→ECRU**, **Pull&Bear→DUB** (продажа сети Daher Group, апрель-май
     2023; юрлица Massimo Dutti/Oysho/Stradivarius/Bershka ликвидированы в
     2023–2024 без упоминания ребренда для этих трёх).
   - `pages/h-m.html` — H&M: полный exit (закрытие последнего магазина конец
     2022, юрлицо ликвидировано 05.2025), официального ребренда нет — просто
     закрылись.
   - `pages/{nike,adidas,new-balance,puma,levi-strauss,uniqlo-fast-retailing}.html`
     — статусы `Leave`, без упоминания преемника.
   - `pages/{mango,marks-spencer,victorias-secret}.html` — **пустые файлы**,
     `errors.log` фиксирует `HTTP500` при краулинге. Данных нет, WebSearch
     недоступен → **эти три бренда не включены** (не подтверждён даже факт
     деталей ухода локально, выдумывать не стали).
   - `pages/{lpp-group,sinsay}.html` — статус **`Stay`/`Scaling Back`**, то есть
     LPP Group (владеет Reserved, Cropp, Sinsay, Mohito, House Brand) по факту
     **не ушла**, просто сокращает присутствие → **Reserved, Cropp, Sinsay не
     включены** (не ушедшие бренды, включать их в рубрику «чем заменить
     ушедших» было бы фактической ошибкой, хотя исходный бриф их предлагал).

2. **docs/ проекта.**
   - `docs/tj_wear_russian_benchmark.md` (коммит 56bf993) — использован как
     источник кандидатов-альтернатив (Krakatau, Gate31, Shu, Линии и т.д. —
     часть уже была в каталоге).
   - `docs/press_kit.md` — контекст масштаба каталога, не источник фактов ухода.
   - `var/scripts/wordstat-rebrand-sweep.sh` — единственное место в проекте,
     где зафиксирована пара **Stradivarius→VILET** (плюс Decathlon→DESPORT,
     Reebok→Sneaker Box — не вошли, см. ниже). Это гипотеза для будущей
     Wordstat-проверки, живых данных по ней не собрано (ключ был мёртв на
     момент записи гипотезы — см. `docs/wordstat-dead-key-gotcha.md` в
     памяти). Поэтому Stradivarius→VILET помечен `confidence: medium`.

3. **Общеизвестные факты** — использованы только там, где совпали с локальными
   источниками (Zara/Bershka/Pull&Bear — см. п.1). Отдельно не выдумывался ни
   один факт, не подтверждённый локально.

## Что не вошло и почему

| Бренд | Причина исключения |
|---|---|
| Mango, Marks & Spencer, Victoria's Secret | Нет данных — краулинг архива упал (HTTP500), WebSearch недоступен. Не выдумываем факт ухода/преемника. |
| Reserved, Cropp, Sinsay | По архиву LPP Group и Sinsay статус `Stay/Scaling Back` — **не ушли**, сокращают присутствие. Включать как «ушедших» — фактическая ошибка. |
| Decathlon (→ DESPORT, из wordstat-rebrand-sweep.sh) | Мультикатегорийный ритейлер спортоваров (велосипеды, кемпинг, фитнес-инвентарь), а не преимущественно одежда/обувь/аксессуары — не вписывается в фокус рубрики каталога одежды. |
| Reebok (→ Sneaker Box), Mothercare (→ Mother Care) | Нет отдельной страницы в архиве leave-russia (`pages/`) для факт-чека статуса ухода; пары есть только как непроверенная гипотеза в wordstat-rebrand-sweep.sh. |
| Massimo Dutti, Oysho, Stradivarius (успешники) | Stradivarius→VILET включён с `confidence: medium` (см. выше). Для Massimo Dutti и Oysho архив прямо говорит о ликвидации юрлица **без** упоминания ребренда — оставлено `successor: ""`, чтобы не придумывать. |

## Проверка альтернатив по каталогу

Стиль/ниша брали из таблицы `brand_style` (JOIN `brand_style_brand`), плюс
точечный поиск по `description`/`anons`/`tagline` (LIKE `%джинс%`, `%кроссов%`,
`%белье%`, `%пижам%`) для нишевых категорий (деним, обувь, домашняя одежда).

**Важно:** каталог содержит утечки зарубежных брендов (см. память
`foreign-brands-policy.md` — стоп-кран 04.07, TODO бэкафилл ~7k). Во время
подбора кандидатов среди результатов JOIN попались `uniqlo` (slug `uniqlo`,
`origin_status='foreign'`, Япония), `adidas Originals`, `adidas YEEZY`,
`Betty Barclay`, `Bikkembergs`, `Anta`, `Altra`, `ACBC` (все с
`origin_status IN ('foreign','unknown')`) — **все исключены** из
альтернатив. Финальный список фильтровался строго `origin_status='ru'`.

Финальная сверка (все 28 slug из yaml, один IN-запрос):

```sql
SELECT slug, status, origin_status FROM brand
WHERE slug IN ('gate31','12storeez','chois','uniquefabric','tvoe','finn-flare',
'breakdownbrand','zny','codered','ymkashix','ostrovimenitebya','red-bow-story',
'netoclothes','molotov','shu','streetrepublic','4forms','studio-29','lesyanebo',
'myza','unison','21maison','fashion-loves-women','lych-project','anteater',
'afour','comfers','larusodejda')
ORDER BY slug;
```

Результат: все 28 — `status='active'`, `origin_status='ru'`. ✅

## Как пополнять

1. Найти факт ухода — сначала в `~/seo-archives/leave-russia/pages/<slug>.html`
   (slug бренда на leave-russia.org), затем в `fashion_brands.tsv`. Если файла
   нет/пустой — see `errors.log`/`crawl.log`; не выдумывать.
2. Преемника подтверждать по тексту страницы (напр. `inditex.html` даёт связку
   через одну статью на несколько брендов группы) — если явно не назван,
   `successor: ""`.
3. Альтернативы — JOIN `brand`↔`brand_style_brand`↔`brand_style` по нишевым
   стилям (`SELECT id, title, slug, slug AS style FROM ...`), либо LIKE по
   `description`/`anons`/`tagline` для узких ниш. **Обязательно** фильтровать
   `status='active' AND origin_status='ru'` — не полагаться только на
   `status`, каталог содержит зарубежные утечки под RU-звучащими и обычными
   названиями.
4. Финальный slug-лист — перепроверить одним IN-запросом перед коммитом.
