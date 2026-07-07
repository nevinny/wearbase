# Аудит /rss/dzen.xml на соответствие спеке Дзена

> Дата: 2026-07-07. Спека: https://dzen.ru/help/ru/website/rss-modify.html
> Проверялись: живой фид https://wearbase.ru/rss/dzen.xml, `src/Controller/DzenFeedController.php`, `templates/rss/dzen.xml.twig`, `src/Service/Seo/ArticleMarkdownParser.php`.

**Вердикт: НЕ соответствует.** XML валиден, каркас (channel, item, guid, pubDate RFC-822, category=native-draft, CDATA) корректен, но есть 2 блокера и 3 риска.

> **UPD 2026-07-07 (коммит ab3b93a):** исправлены №1 (таблицы→списки, `toDzenHtml()` в контроллере), №2 (fallback до MIN_ITEMS=10), №4 (strong/em/hr), №5 (media:rating + namespace). Открыт только №3 (enclosure-обложки): у статей нет per-article картинок вообще (блог использует общий og-image.svg, SVG Дзен не принимает) — нужен отдельный проект генерации обложек, пока обложки ставятся руками при публикации черновика (как и было по методологии).

## Сверка требований

| Требование спеки | Статус |
|---|---|
| channel: title / link / language | OK |
| Namespaces content, dc, media | Частично: нет `media` |
| item: title + дубль заголовка H1 в content:encoded | OK (контроллер, строки 65–66) |
| item: category (native-draft) | OK |
| item: guid стабильный | OK (guid=URL, isPermaLink) |
| item: pubDate RFC-822 | OK |
| item: link ЧПУ без UTM | OK |
| item: enclosure (обложка ≥700px) | **НЕТ** |
| item: media:rating | **НЕТ** |
| content:encoded полный текст ≥300 знаков | OK |
| Только разрешённый HTML (p,a,b,i,u,s,h1–h4,blockquote,ul/ol>li) | **НАРУШЕНО**: table/…/td, strong, em, hr |
| Изображения в теле (figure/img) | нет вообще |
| При первой разметке ≥10 материалов | **НАРУШЕНО**: в фиде 2 |
| robots.txt, UTF-8, cap ≤500 | OK |

## Нарушения по серьёзности

1. **БЛОКЕР: таблицы в content:encoded.** Дзен не обрабатывает таблицы — блок «Сравнение брендов» (ядро листикла) будет выброшен. Источник HTML: `ArticleMarkdownParser.php:100–122`. Фикс: НЕ трогать парсер (его HTML нужен блогу), добавить Дзен-трансформ в `DzenFeedController::feed()` (рядом со стрипом ld+json, ~строка 65): `<table>` → последовательность `<p>`/`<ul><li>` по строкам.
2. **БЛОКЕР онбординга: <10 материалов.** Гейт `fetchYandexIndexedSlugs` (in_search=1, `DzenFeedController.php:43,51,101–115`) пропускает только 2 статьи. Фикс: fallback — если проиндексированных <10, добирать последние опубликованные с привязанной Дзен-копией.
3. **РИСК (высокий): нет обложек.** 0 enclosure / img / figure → карточки без картинок. Фикс: `<enclosure url="…" type="image/jpeg">` в `dzen.xml.twig` после `<pubDate>`, абсолютный URL, ширина ≥700px.
4. **РИСК (средний): strong/em/hr.** Заменить в Дзен-трансформе: `strong→b`, `em→i`, `hr` удалить. Источник: `ArticleMarkdownParser.php:64,132–133`.
5. **РИСК (низкий): нет media:rating.** Добавить `xmlns:media="http://search.yahoo.com/mrss/"` в `<rss>` и `<media:rating scheme="urn:simple">nonadult</media:rating>` в item.
6. **Косметика:** `dc:creator` Дзен игнорирует (безвреден). pubDate 10-дневной давности — для native-draft игнорируется, вопрос каденса, не структуры.

## Принцип фиксов

Все HTML-преобразования — только в Дзен-специфичном трансформе внутри `DzenFeedController` (или отдельном сервисе), чтобы не задеть блог, который использует тот же HTML из `ArticleMarkdownParser`.

## Открытый вопрос

Иконки «обязательно/рекомендуется» в таблицах спеки текстовым извлечением не восстановлены — статусы для `enclosure`/`media:rating` выведены из канонического примера ленты. На блокеры №1–2 не влияет.
