# Глобальные аналоги WEARBASE — что копировать

> Разбор: на какую зарубежную модель похож WEARBASE и какие фичи перенять.
> Ревизия: 2026-06-23.

## Постановка вопроса

Яндекс.Такси скопировал Uber, Самокат — Gopuff/Getir. У этих кейсов был **чистый
прототип 1:1**. У WEARBASE такого нет — это гибрид, и драйвер другой: не перенос
работающей за рубежом модели, а **импортозамещение после ухода западных брендов в
2022** (возник спрос «где найти российские бренды одежды» → дыра в дискавери). Этого
контекста на Западе нет.

Поэтому правильная рамка — не «чей мы клон», а «**из каких трёх площадок собрать
эталон по слоям**».

## Три аналога по слоям

| Площадка | Модель | Насколько = WEARBASE |
|---|---|---|
| **NOT JUST A LABEL (NJAL)** | Каталог + персональный шоурум бренда; e-commerce **без комиссии**, уводит на собственный магазин дизайнера; монетизация подпиской (NJAL+). 50k+ дизайнеров, 150+ стран | **Почти 1:1 текущая модель WEARBASE.** Ближайший эталон |
| **Lyst** | Inventory-free поисковик (5M товаров, 12k брендов); **affiliate-комиссия** с клика-в-покупку; AI/языковой поиск; partner-дашборд (CPA/conversion/traffic); универсальная корзина. В 2025 куплен Zozo | **Следующий шаг по монетизации** — transaction-трекинг поверх каталога |
| **Wolf & Badger** | Курируемый маркетплейс независимых брендов; берёт на себя логистику/возвраты/налоги; комиссия + годовой фи (~$150/мес); своя фото-студия; B-Corp | В основном **неприменимо** (capital-heavy, берёт сделку на себя — то, чего WEARBASE сознательно избегает). Берём только ветку курирования качества |

**Вывод:** копировать продуктовые механики **NJAL**, заимствовать монетизационный слой
**Lyst**, из **W&B** взять только лёгкое курирование/верификацию — не операционку.

Связь со стратегией: это согласуется с [marketing_strategy.md](marketing_strategy.md)
(враг — маркетплейс-«арендодатель», движение «Прямой бренд») и moat «0% комиссии» из
[competitors.md](competitors.md). NJAL — внешнее доказательство, что no-commission-модель
жизнеспособна; Lyst — предупреждение, что без трекинга ценности она бедно монетизируется.

## Что брать — под текущий стек

Приоритет по принципу «дёшево, потому что инфраструктура уже есть».

### 🟢 Дёшево — переиспользовать готовое

1. **Виртуальный шоурум бренда (NJAL)** — апгрейд страницы бренда.
   Есть `tailwind/brand/show.html.twig` + RAG-корпус (сайт+WB+соцсети+упоминания).
   Превратить «текст для SEO» в **структурированный профиль-портфолио**: архив
   коллекций, история, ценовой сегмент, материалы, «где купить». Это отличает
   дискавери-платформу от справочника. → данные доставать из RAG-корпуса
   ([rag_pipeline.md](rag_pipeline.md)).

2. **Editorial-слой + «Индекс брендов» (NJAL + Lyst).**
   У обоих контент — двигатель трафика и PR (NJAL — эссе/интервью; Lyst Index — PR-машина
   и ссылочная масса). Есть блог + весь LLM-стек. Дешёвый аналог: **«Индекс российских
   брендов»** на собственных данных (рост упоминаний, Wordstat-кейворды, активность) —
   самовоспроизводящийся инфоповод и ссылки.

3. **AI/семантический поиск брендов (Lyst 2025).**
   Lyst ушёл от фильтров к «опиши словами, что ищешь». Есть embedding-инфра
   (qwen3-embedding → Qdrant) под RAG — тот же вектор-стор переиспользовать для
   семантического поиска («тихий минимализм из Питера до 10к»).

### 🟡 Средне — нужна новая сущность/логика

4. **Партнёрский дашборд с метриками (Lyst).**
   Бренд должен видеть, что платформа **приносит**: показы профиля, клики «перейти в
   магазин», переходы по соцсетям. Есть brand LK (`brand_lk/layout.html.twig`) — добавить
   аналитику. Главный аргумент удержания подписки.

5. **Лёгкое курирование/верификация (W&B vetting → minimal).**
   Не нужна строгость W&B, но **бейдж «проверенный бренд»** (живой сайт, реальные
   контакты, активность) повышает доверие и даёт повод для платного тира. Половина
   сигнала уже есть — `brand.contact_status` (enriched/partial/...).

### 🔴 Стратегический — закрывает главную дыру модели

6. **Affiliate / click-трекинг переходов к бренду (Lyst-core).**
   NJAL остаётся бедным именно потому, что отдаёт переход бесплатно. Lyst построил весь
   бизнес на **трекинге клика-в-покупку**. Даже без комиссии WEARBASE критически нужно
   **мерить исходящий клик** (UTM/redirect через свой эндпоинт):
   - это метрика ценности для удержания подписки **сегодня** (питает п.4);
   - это опцион на transaction/affiliate-монетизацию **завтра** (уход от ловушки NJAL).

   Технически — маленький контроллер-редиректор + таблица кликов. **Делать первым из
   «красных».**

## Маршрут

```
Сейчас (дёшево, переиспользовать готовое):
  шоурум-профиль брендов  →  семантический поиск (Qdrant)  →  «Индекс брендов» как PR
Затем (удержание подписки):
  click-трекинг переходов  →  аналитика в brand LK  →  бейдж «проверенный»
Позже (развилка монетизации):
  affiliate-трекинг сделок (опц. — уход от чистого no-commission)
```

Главный нерв — **п.6 (трекинг клика)**: одновременно метрика ценности для удержания
подписки и опцион на transaction-монетизацию. Без него WEARBASE остаётся в монетизационной
ловушке NJAL.

## Региональные аналоги NJAL (Европа / Латам / Азия)

Прямого клона NJAL «по миру» нет — собирается из площадок по слоям (директория-шоурум /
кураторский маркетплейс / инкубатор-событие).

| Регион | Площадки | Чему учат |
|---|---|---|
| **Европа** | Vistoya (кураторская, AI-native — позиционирует себя как замену NJAL), Space to Show (чистый шоурум-директория), Carnet de Mode, Curated Crowd, W&B | Ниша **тесная**; Vistoya бьёт NJAL ровно кураторством + AI-discoverability + интегрированной коммерцией |
| **Латам** | Latinoamérica de Moda (директория+медиа), Mockingbird (MX), Casa de Criadores (BR, инкубатор), Nuvemshop (энейблер) | Единого регионального NJAL **нет** — страновые директории + инкубаторы + Shopify-энейблеры. Ниша пустая |
| **Азия** | **Korea: Musinsa, W Concept, 29CM** (local-first платформы IPO-масштаба); China: Labelhood (инкубатор+ретейл); India: Pernia's Pop-Up | Модель «платформа вокруг отечественных дизайнеров» доросла до сотен млн $. Япония/ЮВА — пусто, как Латам |

Два вывода: (1) Корея — доказательство, что local-first дискавери масштабируется до люкс-оборотов
(главный кейс — Musinsa, ниже); (2) нарратив Labelhood «дизайнерам не нужно западное признание»
= позиционирование WEARBASE «Прямой бренд» почти дословно.

## Кейс Musinsa: перенос

Главное: Musinsa — **контент-сообщество, доросшее до коммерции**, а не маркетплейс с контентом.
Траектория: 2001 — онлайн-сообщество уличных снимков → 2005 Musinsa Magazine (интервью emerging-
дизайнеров) → **только 2009** e-commerce (на росте сообщества, не на VC) → 2024 оборот >1 трлн вон
(~$680 млн). WEARBASE стоит на том же контент-first фундаменте (RAG/блог/каталог) → находится в
начале того же флайвила, а не «опаздывает с продажами».

**Послойно — что переносится:**

| Слой Musinsa | Переносится | Как на стеке WEARBASE |
|---|---|---|
| Editorial-движок (Magazine: интервью/съёмки/styling) | ✅ прямо | LlmService+блог+RAG-корпус → от SEO-текстов к историям/интервью брендов |
| Rankings (real-time best по товару и **бренду**) | ✅ дёшево | = «Индекс брендов» (выше). Данные есть: Wordstat, GSC, просмотры |
| Данные → персонализация / AI-дискавери (вся конверсия Musinsa на данных) | ✅ средне | Qdrant под семантический поиск + рекомендации брендов |
| Онбординг + support дизайнера (free-экспозиция, съёмка, расчёты) | ✅ прямо | = оффер «под ключ 5000₽» ([sales_offer.md](sales_offer.md)) — главный крючок удержания |
| Street-snap / UGC | 🟡 частично | Не продаём → агрегация лукбуков брендов, не примерочные |
| **Musinsa Standard** (private label, ~25% продаж) | ❌ противоречит | Конкуренция со своими брендами ломает moat «0% комиссии» |
| Offline + global (33 магазина, 13 рынков, JV с Anta) | ⏸ не сейчас | Поздние стадии |

**Развилка монетизации.** Musinsa признала, что чистого take-rate мало → достроила private label +
офлайн. Для WEARBASE прямой перенос (своя марка/комиссия) ломает moat. Аналог «достройки» —
монетизировать **дискавери-ценность вокруг сделки, а не внутри неё**: подписка (есть) +
done-for-you услуги (5000₽) + affiliate/click-трекинг + data-продукты (аналитика спроса бренду).

**Вывод:** Musinsa-урок — **данные о спросе и переходах = фундамент, раньше контента-ради-контента**.
Половина данных уже копится (Wordstat/GSC); не хватает ровно **клик-трекинга** (п.6 выше) — он же
закрывает удержание подписки и будущий affiliate. Третий независимый довод сделать его первым.

## Источники

- NJAL — [how it works / NJAL+](https://notjustalabel.com/about/how-njal-works),
  [Introducing NJAL+](https://industry.notjustalabel.com/editorial/introducing-njal)
- Lyst — [AI search focus, Glossy](https://www.glossy.co/fashion/luxury/luxury-briefing-post-acquisition-lyst-is-focusing-on-ai-search-for-luxury-shopping/),
  [business model / affiliate, Vizologi](https://vizologi.com/business-strategy-canvas/lyst-business-model-canvas/),
  [partner dashboard, Econsultancy](https://econsultancy.com/lyst-on-the-state-of-fashion-retail/)
- Wolf & Badger — [seller model, Glossy](https://www.glossy.co/fashion/wolf-badger-is-positioning-itself-as-an-anti-saks-to-brands-seeking-new-sales-channels/),
  [fees & vetting, Modern Retail](https://www.modernretail.co/operations/marketplace-briefing-how-wolf-badger-is-winning-u-s-shoppers-despite-luxury-retails-slowdown/)
- Регионы — [NJAL alternatives 2026 (Vistoya, маркетинг)](https://vistoya.com/publication/not-just-a-label-alternatives-fashion-designers-2026),
  [Carnet de Mode / Space to Show / Garmentory (Coveti)](https://coveti.com/top-emerging-fashion-designers-marketplaces-to-discover-now/),
  [Latinoamérica de Moda (Grazia MX)](https://graziamagazine.com/mx/articles/latinoamerica-de-moda-la-plataforma-para-las-emprendedoras-de-la-moda/),
  [Casa de Criadores (FashionUnited)](https://fashionunited.com/news/fashion/casa-de-criadores-concludes-2025-brazilian-fashion-season/2025122969800),
  [Labelhood (RADII)](https://radii.co/article/labelhood-dongliang-chinese-fashion),
  [Pernia's Pop-Up](https://www.perniaspopupshop.com/designers)
- Musinsa — [история community→commerce](https://businessmodelcanvastemplate.com/blogs/brief-history/musinsa-brief-history),
  [Magazine / street-snap / rankings](https://about.musinsa.com/newsroom/about-musinsa),
  [data/AI-дискавери (Databricks)](https://www.databricks.com/customers/musinsa),
  [Musinsa Standard / private label / офлайн (Korea Herald)](https://www.koreaherald.com/article/10639378),
  [Китай JV с Anta / global (Inside Retail Asia)](https://insideretail.asia/2025/09/03/south-korean-fashion-retailer-musinsa-reveals-plans-for-china-foray/)
