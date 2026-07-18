# SEO/GEO-бэклог WEARBASE (по дайджесту DrMax)

> Источник приёмов — [docs/drmax_seo_2026_digest.md](drmax_seo_2026_digest.md) (§/msg-ссылки ниже).
> Составлено 2026-07-19 по grounded-аудиту (страница бренда + site-wide поверхность).
> Статус: **предложения**. Порядок разделов — по типу; приоритет и усилие — в каждой строке.
> Усилие: S (часы) · M (день-два) · L (крупное/поэтапно).

Эпоха zero-click: **KPI — клики и реферальные переходы, НЕ показы** (§5 msg 1464, показы GSC были завышены). Для AI-видимости — Share of AI Presence / Bing Citation Share (§11 msg 1551), не позиции.

---

## ✅ Уже сделано (не ломать)
- Описание бренда бьётся на абзацы «одна идея — один абзац» (`show.html.twig:294`) · контекстный alt логотипов аналогов · `role=seo` в советнике.
- Сильный техкаркас: noindex не-ru локалей (правильно — иначе semantic collapse, §11 msg 1588), фасеты `brand_index` → noindex+canonical-срез query (§9 msg 1240), каталог noindex+self-canonical (пусто), 410 для закрытых брендов / 404 для пустых хабов (crawl-гигиена), приоритет brand-URL в sitemap по объёму описания (анти-thin, §6), robots Content-Signal `ai-train=no` + allow AI-краулеров, llms.txt с `MIN_DESC_LEN`.
- Листиклы/гиды (`GenerateListicleCommand`/`SeoGuideCommand`, `docs/seo_boost.md`) — **эталон GEO-канона**: answer-nugget «Коротко» 40–60 слов, таблица сравнения, `verify-standalone`/`verify-factual-density`, ItemList+FAQPage, meta-title≠H1 с годом, `republish` для свежести, LLM-судья.
- `OutboundClickController` — защищаемая метрика реф-клика (§5/§11). FAQPage только из реального `brand_faq`. Entity-сигналы бренда (foundingDate/Location, sameAs, LocalBusiness). similarBrands (MIN 6) — нет тупиков для PageRank. Блог: байлайн + «обновлено {дата}».

---

## 🔴 HIGH

### 1. Тонкие программные гео/стиль-лендинги — spam-риск + упущенная AI-цитируемость `M`
Города/стили без кураторского `CityHub`/`style.description` = H1 по формуле + 1 generic-абзац ~40 слов + сетка логотипов; таких URL десятки-сотни в sitemap. `city.html.twig:59-64`, `style.html.twig:59-60`, `SitemapController.php:95-132`.
- **Почему:** §7 msg 1567 — SpamBrain бьёт по шаблонам групп «[услуга]+[город]»; §8 msg 1422 «10 сильных > 100 шаблонных»; §9 msg 1457 — ИИ читают видимый HTML (а его почти нет), не JSON-LD.
- **Как:** гейт индексации — хаб с <3 брендами → `noindex,follow` и вон из sitemap; остальным — кураторский intro (сколько брендов, доминирующие стили, ценовые сегменты, офлайн-точки) + 2–4 extractable-блока: H2-вопрос («Какие бренды одежды шьют в {city}?») + прямой ответ 40–60 слов. Наполнять топ-10 по спросу (`app:seo:ranking`) постепенно.

### 2. GSC не пишет query — regex-свип под AI Overviews физически невозможен `M`
`SyncGscCommand.php:109-111` тянет Search Analytics только по `page`, колонка `query` = NULL.
- **Почему:** блокирует всю задачу §5 (msg 1612/1614) — мастер-regex применяется к тексту запросов, а их в БД нет (вопросы trigger rate ~58%, freshness 100%).
- **Как:** второй pull с `dimensions=['query']` → таблица `gsc_query_stats` → прогон RU-мастер-regex → приоритет генерации блоков «H2=вопрос + ответ 40–60 слов» на категориях/хабах/блоге. **Это разблокирует то, ради чего затевался regex-свип.**

### 3. Нет ни одной метрики AI-видимости `M`
Только классический GSC (page) + OutboundClick.
- **Почему:** §7 msg 1554 (GSC Performance→Generative AI), §11 msg 1551 (Bing Citation Share — первый рабочий GEO-интерфейс).
- **Как:** подключить Bing Webmaster → экспорт Citation Share по страницам → обогатить ценностью из OutboundClick/CRM → приоритет **High Value / Weak Share**; свести в утренний дайджест рядом с `outbound_click_count`. Citation Share — наблюдательная метрика, не KPI.

### 4. Блог оторван от каталога (сироты по линковке) `M`
`blog/show.html.twig` — один CTA на `/brands` + «Все статьи». Нет «Похожие статьи», нет «Бренды из статьи», `Article` не связан с `Brand`.
- **Почему:** §1 fan-out (блок «По этой теме» 3–5 ссылок), §10 семантический меш, §3 msg 1432 перелинковка по марже. PageRank/тематическая связность блог↔каталог не текут.
- **Как:** блок «Похожие статьи» (по тегу/свежести) + «Бренды из статьи» (M2M `Article↔Brand` или парс slug) + in-text ссылки в pulled-статьях.

### 5. Карточка бренда: answer-first extractable-блок + negative-definition `M`
Первый видимый текст — `anons`, не самодостаточный ответ; нет «чем бренд НЕ является». `show.html.twig:293`.
- **Почему:** §1 msg 1189/1267 (extractable-блок 40–80 слов, первые 150–200 слов = машинный интерфейс), §3 msg 1590 (negative definitions, +70% цитируемости). Позиционирование WEARBASE — сам по себе контраст (российский, не масс-маркет, не иностранный аналог, прямые продажи).
- **Как:** grounded-блок «Коротко о {бренд}» первым (город, год, категория, сегмент + «В отличие от масс-маркета/иностранных аналогов…») — генерить в `BrandRagService`/генераторе (не в шаблоне, чтобы grounded) + правка промпта `LlmService::generateBrandDescription` (answer-first, H2-вопросы, information gain, GIST-принцип «объём на редкие факты, не на общее»).

---

## 🟡 MED

### 6. crawl-бюджет: фасеты каталога не закрыты в robots `S`
`public_html/robots.txt` блокирует только utm/ref; каталог даёт комбинаторный взрыв `?sort,min_price,max_price,size,q…` (`CatalogController.php:113-138`). Meta-noindex виден только после загрузки → бюджет тратится. → §6 msg 1466, §9. **Как:** `Disallow: /*?*sort= *min_price= *max_price= *size= *q=`; пагинацию/gender оставить.

### 7. Дубль/пустой Organization на главной + нет Wikidata-якоря `S`
`hub.html.twig:46-56` — второй `Organization` с `sameAs:[]` поверх заполненного из `base.html.twig:71-83`. → §4 msg 1564/1585 Entity Split&Collision. **Как:** удалить дубль в hub; в базовый добавить `@id` + Wikidata/Crunchbase в `sameAs` (Data Provenance Lock от entity-poisoning) для заметных брендов/сущности WEARBASE.

### 8. og:image = SVG (не рендерится соц/AI-превью) `S`
Все шаблоны → `/og-image.svg`, хотя `og-image.png` существует. `base.html.twig:44-45` декларирует 1200×630 (для SVG бессмысленно). **Как:** переключить на `.png`.

### 9. Свежесть: нет lastmod/dateModified на гео/стиль/хаб/блоге-цикле `M`
Sitemap даёт lastmod брендам/статьям/авторам, но не city/style/hub/landings; блог `republish` только для листиклов. → §3 msg 1504 (~90 дней срок годности, 50% AI-цитат младше 13 недель; кейс David Baum). **Как:** lastmod = MAX(brand.updated_at) по выборке хаба + `dateModified` в schema + видимое «Обновлено»; 60-дневный триггер-дослой (не переписывание) для топ-статей, расширить `app:seo:republish-blog` на редакционные. ⚠️ бампать дату только при реальном изменении контента (иначе штраф за искусственную свежесть).

### 10. Boilerplate-FAQ на карточках без реального brand_faq `S`
`show.html.twig:537-584` — 4 родовых Q/A почти идентичны на тысячах страниц (Schema для них не выводится, но видимый текст остаётся). → §3 msg 1599 (contentEffort→0), §7 (шаблоны). Угроза cross-page similarity гейта. **Как:** прятать блок без grounded-данных ИЛИ расширить `generateBrandFaq` (Wordstat) на больше брендов.

### 11. WebSite SearchAction указывает на буквенный фильтр `S`
`base.html.twig:67` target = `/ru/brands?letter=` — подстановка запроса даст мусор. Реальный поиск — `catalog?q=`. **Как:** переключить target на `catalog?q={search_term_string}`.

### 12. Фасетная навигация каталога — long-tail на будущее `L`
Когда появятся товары: ЧПУ для приоритетных комбо (`/catalog/women/streetwear`), вводный текст (3 абзаца: классификация→боли→навигация, сущности из фильтров), self-canonical, noindex хвоста; приоритет по показам GSC. → §9 msg 1240. Сейчас — пометка, каталог пуст.

### 13. Вопросные H2 на карточке бренда `S`
«О бренде X»/«Характеристики X»/«Магазины X» → «Что за бренд X?»/«Что производит X?»/«Где купить X?»/«Сколько стоит X?». → §2 msg 1204, §5 msg 1617 (H2=запрос → якорь retrieval, совпадает с fan-out).

### 14. llms.txt — гео/стиль-кластеры + дата `S`
`LlmsTxtController.php:53-60` — только общие разделы. → §1 msg 1489 (кластер = единица работы для AI). **Как:** секции «## Города»/«## Стили» (топ-10-20 по числу брендов) + строка даты генерации; в robots добавить ссылку на `/llms.txt`.

---

## 🟢 LOW / NICE
- **Sitemap-index** + под-карты (brands/geo/blog/authors) — до приближения к лимитам 50k/50МБ. `SitemapController.php`. `M`
- **Код-до-контента** в `<head>`/header: tailwind-CDN + два мега-дропдауна (locale×9, currency) дважды до `<main>` — доля DOM до сути на тонких хабах. Вынести ниже в `<nav aria-label>`. → §2 msg 1580. `M` (аккуратно — риск регрессий вёрстки).
- **Интро каталога** (`brand/index.html.twig:73`) + **alt в сетке** (`:144`, только title) → information-gain intro + формула alt `[что]+[контекст]+[почему]`. §9/§2. `S`
- **CollectionPage без isPartOf #website** на city/style/cities. §4. `S`
- **Memory anchors** (§1 msg 1299): 1–2 контрастные фразы ≤15 слов над сгибом на hub/лендинге, переиспользовать в соцсетях. `S`
- **Единый primary statement** WEARBASE — сверить совпадение на hub/About/соцсетях/авторской (§4 msg 1564). `S`
- **Dead code** `BrandsController.php:711-788` (`createDemoProducts`, недостижим) — удалить при случае.

---

## Рекомендованный порядок
1. **Дешёвые техно-фиксы** (§6 robots, §7 Organization, §8 og:png, §11 SearchAction, §14 llms.txt) — S, разом.
2. **Разблокировать измерение** (§2 GSC query, §3 Bing Citation Share) — без этого regex-свип и оценка AI-эффекта невозможны.
3. **Гейт тонких хабов** (§1) — снять spam-риск, потом наполнять топ.
4. **Контентный пакет карточки бренда** (§5 + §13) одной правкой промпта/генератора, прогон на 5–10 брендах через QA-гейт.
5. **Линковка блог↔каталог** (§4) и **свежесть** (§9).
