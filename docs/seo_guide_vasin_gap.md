# Гайд «Свой SEO-сайт под ключ с Claude Code» (Васин, v2.2 PRO, 14.07.2026) — сверка с WEARBASE

Источник: vasinki.ru/guides/seo-zavod-pro-9972a73ba543.pdf (36 стр.), t.me/vasin_launch.
Сверено 2026-07-28. Гайд про статейный сайт на Next.js + агенты в GitHub Actions; мы —
Symfony-каталог, поэтому сверяем только методику (SEO/контент/агенты), не стек.

## Что у нас уже есть (гайд не добавляет ничего)

| Пункт гайда | У нас |
|---|---|
| IndexNow + один Google-канал | `IndexNowPinger` + `app:google:index-ping` (anti-trifecta) |
| sitemap/robots, чистые URL, латиница нижним регистром | `SitemapController` (+ `sitemap-blog.xml`), `robots.txt` c Disallow utm/ref, `app:brand:fix-slugs` |
| self-canonical | `tailwind/base.html.twig`, non-ru → noindex + canonical на ru |
| Полный набор JSON-LD (Organization, WebSite, WebPage, BreadcrumbList, Article, Person, ImageObject, FAQPage, CollectionPage, ItemList) | всё есть + Product/Brand/AggregateOffer/LocalBusiness |
| E-E-A-T: автор, дата обновления, FAQ | Author entity, байлайн, `/author/`, `app:brand:faq` |
| GSC + Яндекс.Вебмастер | `app:gsc:sync`, `app:yandex:sync` |
| Актуализация статей (dateModified + синонимизация меты + абзац) | `app:seo:republish-blog` |
| Антиканнибализация | `NearDuplicateDetector` + `app:seo:near-dup` |
| Ежедневный/недельный отчёт в TG, стратег-приоритеты | `app:report:daily/weekly`, `app:advisor:*` |
| Ключевики Вордстата | `app:brand:keywords` (`brand_keyword`) |
| GEO / llms.txt | `LlmsTxtController`, `AgentReadyListener`, Content-Signal в robots |
| Дистрибуция, монетизация (РСЯ, лиды) | соцконвейер, Дзен-RSS, РСЯ-блоки, аутрич |
| Контент «от данных» | сильнее гайда: RAG на реальном корпусе (гайд — LLM по брифу) |

## Пробелы — по убыванию ценности

1. ✅ **Сделано 2026-07-28** — **дожим позиций 4–15.** `app:seo:gap-report --band=striking`
   (3<pos≤10) рядом с прежним `gap` (>10). Дожим требует URL, поэтому добавлен третий pull
   GSC `dimensions=['query','page']` → таблица `gsc_query_page`
   (`GscClient::searchAnalyticsByQueryPage`, врезка в `app:gsc:sync`). `seo_gap_snapshot` += `band`.
2. ✅ **Сделано 2026-07-28** — **тех-аудит как регулярный сторож.** `app:seo:tech-audit`
   (крон сб 07:30): обход сайта, чек-лист (мета/H1/canonical/alt/дубли title/CTA в заголовке/
   FAQ без `FAQPage`/регистр URL) + битые внутренние ссылки, findings в `seo_tech_finding`,
   в TG только дельта «появилось / исправлено».
3. ✅ **Сделано 2026-07-28** — **страницы-сироты.** Та же команда: кандидаты из sitemap
   (без карточек брендов), обход в две фазы, вывод только при исчерпанной фазе хабов.
   Первый прогон по проду нашёл 3 статьи блога без входящих внутренних ссылок:
   `rossiyskie-brendy-odezhdy-gid`, `pochemu-prodavtsy-uhodyat-s-wildberries`,
   `kak-kupit-odezhdu-napryamuyu-ot-proizvoditelya` (в листинге `/ru/blog` — только 13 статей),
   плюс 42 карточки с FAQ-блоком без `FAQPage` и `demo-brand` без description на проде.
   **Это открытые задачи — аудит их находит, но не правит.**
4. **Core Web Vitals / PageSpeed.** Нет ни команды, ни ключа PageSpeed API. CWV живут
   только текстом в `docs/seo_rules.md`.
5. **Карта семантики с бронью ключей.** `brand_keyword` привязан к бренду; единого реестра
   «кластер → страница-владелец» (`planned → reserved → published`) для блога/листиклов/
   гео-хабов нет. Near-dup ловит совпадение текста, но не совпадение интента.
6. **Калибровка брифа по конкурентам SERP.** Гайд: целевой объём = средняя длина 2–3
   конкурентов из топа по большинству запросов кластера; картинок — среднее +15%;
   инфографика/сводная таблица обязательны, если есть хотя бы у одного; title не должен
   повторять заголовки конкурентов. У нас объём и структура задаются промптом, не данными выдачи.
7. **Исходящие ссылки на трастовые источники.** В шаблоне статьи внешних ссылок нет вообще.
   Гайд: 1–2 на статью (вики, словари, региональные СМИ), списком на премодерацию.
8. **Ссылочный профиль.** Нет ничего: реестр доноров, разовые сабмиты в каталоги,
   крауд 5–10/мес, статейные 7–10/мес при 80–90% безанкорных. `docs/ahrefs.md` — только замер.
   (Согласуется с директивой фокуса: аутрич важнее, но реестр стоит дешево.)
9. **Квиз / геймификация.** Нет. Очевидный кандидат: «подбор бренда по стилю и бюджету»
   отдельной страницей + тизеры в статьях и карточках → заявка/подписка. Гайд отмечает это
   как белый способ поднять поведенческие.
10. **Обложки в блоке «Похожие статьи».** Сейчас только дата + заголовок (`blog/show.html.twig:127`).
    Гайд: реальные обложки поднимают CTR внутренней перелинковки.

## Чего в гайде нет, а у нас есть

Content-Signal в robots, Link-заголовок на llms.txt, ниша-гейт и origin-гейт брендов,
RAG-корпус как источник фактов, publish-drip с рампой, аутрич-воронка с суppression по email,
локальное ревью PR. Гайд рассчитан на одиночный статейный сайт — по инфраструктуре мы выше.
