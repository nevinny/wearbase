# Автоматизация линкбилдинга WEARBASE — план

Проектирование 2026-07-28 (агент `deep-reasoner`, цифры проверены на живых данных).
Закрывает пробел №8 из [seo_guide_vasin_gap.md](seo_guide_vasin_gap.md).

## Вывод

Автоматизируемы только **обнаружение, учёт и верификация** ссылок — добыча остаётся ручной.
Начинать с самого дешёвого и не конфликтующего с WIP=1 (директива фокуса: первая продажа):
реестр доноров, который **уже лежит у нас в БД** (`brand_source_url`: 7 679 URL / 810 хостов
типа `article_review`, 16 221 / 4 380 `mention`), плюс бесплатный монитор беклинков через
уже настроенный токен Яндекс.Вебмастера.

Проверено вживую 2026-07-28: `GET /v4/user/{uid}/hosts/{hid}/links/external/samples` → **200,
count=8**. Весь наш внешний ссылочный профиль — 8 ссылок, и все паразитные (`yapl.ru`,
`bye.fyi`, `jobsapp.info`, `sergechel.info`). Метрика с `YANDEX_METRIKA_TOKEN` отвечает 200 на
`ym:s:referer` (среди рефереров есть `chatgpt.com`). Бонусом Вебмастер отдаёт
`links/internal/broken/samples` = **74 битых внутренних** — это перекрывается с
`app:seo:tech-audit`, сверять.

**Бейдж-программу («мы в WEARBASE») делать дёшево и без ожиданий**: на проде 3 669 карточек,
но `published_at` только у 909, `status=active` — 1 046, а владельцев (`brand_user`) — **2**,
заявок claim — **1**. Аудитории для свопа сейчас физически нет, поэтому бейдж едет в
тёплый/транзакционный контур (письмо после claim, ЛК, пресс-кит), а не отдельной кампанией.

Этапы 1+2 ≈ 1,5 дня разработки. Остальное — руками, 5–10 касаний в месяц.

## Контуры

| Контур | Автоматизируемо | Чем у нас | Стоимость |
|---|---|---|---|
| Реестр доноров (кто пишет про рос. бренды) | **Да** | SQL-агрегация `brand_source_url` по хосту + `UrlFilter` + денилист агрегаторов/UGC (otzovik, irecommend, ozon, zoon, 2gis, megamarket) | ~4 ч |
| Учёт размещений, «не платить дважды», anchor-профиль | **Да** | 3 таблицы + `app:link:check` (фетч страницы → есть ли `<a href>`, анкор, `rel`) | ~5 ч |
| Монитор беклинков | **Да** | Вебмастер `links/external/samples` + `history`; Метрика `ym:s:referer` (referral) | ~4 ч |
| Непролинкованные упоминания «WEARBASE» | **Да** (обнаружение) | `SearxClient` → фолбэк `YandexSearchClient` под `YandexSearchMeter` → `WebScraperService::fetch`, поиск `href*=wearbase.ru` | ~4 ч |
| Бейдж «мы в WEARBASE» | **Частично**: выдача сниппета + верификация | `BadgeController` (SVG), страница в ЛК, `app:link:badge-check` | ~8 ч |
| Swap-запрос третьему донору | **Нет** — только драфт | `app:link:draft-outreach` + человек-гейт | ~5 ч |
| Сабмиты в каталоги, крауд, статейные | **Нет** (формы, капчи, модерация, ToS) | только учёт в реестре | 0 |
| Покупка ссылок, PBN, переиндексаторы | **Исключено** | — | — |

Почему свот-бейдж безопасен: наши исходящие на сайты брендов **уже `rel="nofollow"`**
(`templates/tailwind/brand/show.html.twig:552`, `brand/links.html.twig:67,99`) → это не обмен
PageRank. Обязательное условие: бейдж даётся **всем и бесплатно, без привязки к любой выгоде**
(никакого «поставь ссылку — подымем в выдаче/дадим тариф»), иначе это goods-for-links.

## Модель данных

Миграция `Version2026xxxx_link_registry.php`, `CREATE TABLE IF NOT EXISTS`, soft-delete через
`deleted_at`:

- **`link_donor`** — `host` UNIQUE(191), `kind` (media|catalog|blog|forum|brand_site|aggregator),
  `status` (candidate|contacted|placed|rejected|blacklisted), `brands_covered`, `urls_count`,
  `avg_relevance`, `referral_visits` (из Метрики), `contact_email`, `contact_url`, `notes`,
  `first_seen_at`, `deleted_at`. Идемпотентный upsert по host.
- **`link_placement`** — `donor_id`, `brand_id` NULL (заполнен = бейдж на сайте бренда),
  `source_url` + `source_url_hash`, `target_url` NULL (NULL = упоминание без ссылки), `anchor`,
  `anchor_type` (naked|branded|generic|exact|image), `rel` (dofollow|nofollow|ugc|sponsored),
  `status` (mention_unlinked → requested → live → lost), `discovered_via`
  (yandex_webmaster|metrika|badge|searx|manual), `cost_rub`, `paid_at`, `first_seen_at`,
  `last_checked_at`, `misses`, `lost_at`, `deleted_at`; UNIQUE(`source_url_hash`,`target_url_hash`).
  Один жизненный цикл «упоминание → ссылка → потеряна» — отдельная таблица упоминаний не нужна.
- **`link_outreach`** — `donor_id`, `email`, `type` (swap|mention_fix|guest),
  `status` (drafted|sent|replied|declined), `sent_at`, `attempts`, `last_error`, `deleted_at`.

**Suppression обязателен:** `src/Service/Outreach/OutreachSuppression.php` — `isBlocked(email)` =
`BrandOutreachRepository::isSuppressed()` OR `recentlyContacted($email, 30)` OR свежий
`link_outreach`. Обе отправки идут только через него; кап 30 дней не обходится, follow-up внутри
30 дней запрещён (см. память `outreach-frequency-cap`, `outreach-legal-fz38-consent`).

## Команды и эндпоинты

1. `app:link:donors-seed [--min-brands=3] [--dry-run]` — реестр из `brand_source_url`, без сети.
2. `app:link:sync-yandex` — `links/external/samples` + `history` + `internal/broken`. Только Mac.
3. `app:link:sync-referrals [--days=30]` — Метрика `ym:s:referer`, filter `trafficSource=='referral'`.
4. `app:link:mentions [limit] [--fresh]` — `"wearbase" -site:wearbase.ru` через Searx → фетч →
   `mention_unlinked` либо `live`.
5. `app:link:check [limit] [--fresh]` — верификация: анкор + `rel` + наличие; 2 промаха → `lost`.
6. `app:link:badge-check [limit]` — краул сайта бренда (главная + `/about`, `/contacts`);
   **акцепт = `<a href>` на нашу карточку с домена бренда, HTTP 200, `rel` не `sponsored`,
   вне `<noscript>`** (картинка без ссылки акцептом не считается); переверификация каждые 14 дней.
7. `app:link:draft-outreach [limit]` — драфты + TG, **ничего не отправляет**.
8. `app:link:send --ids=… [--cap=5]` — кап 5/день, через `OutreachSuppression`, текст-уведомление
   без цены и оффера, unsubscribe; отправка НЕ с домена `wearbase.ru`.
9. `app:link:report` — недельный блок в TG: +live/−lost, anchor-микс, доноры по статусам,
   adoption бейджа; врезать в `app:report:weekly`.

Эндпоинты (прод): `GET /badge/{slug}.svg` (серверный SVG, без JS, `Cache-Control: public,
max-age=3600`, бот-фильтр по образцу `OutreachController`), `GET /brand/badge` (превью, готовый
HTML/Markdown-сниппет, статус «нет / найден на URL / был и пропал», кнопка проверки с
rate-limit), публичная страница с правилами. Сниппет всегда безанкорный/брендовый + `?utm_source=badge`.

**Порядок:** ① реестр + `check` + `report` (~1 день, только Mac) → ② `sync-yandex` +
`sync-referrals` (~4 ч) → ③ бейдж (**после** первой продажи либо вместе с правкой claim-флоу)
→ ④ `mentions` + драфты → ⑤ вечно ручное: сабмиты, крауд, статейные.

## Риски

- **Массовый реципрокный обмен = ссылочная схема.** Три условия: наши исходящие остаются
  nofollow, бейдж бесплатен и ничем не обусловлен, никаких денег донорам. Просит оплату →
  `status=blacklisted` + `notes` (это же защита «не платить дважды»).
- **Потолок бейджа сейчас — единицы брендов** (2 владельца, 1 claim). Не выделять кампанию и
  не трогать холодное письмо: просьба о бейдже — только тёплый контур, иначе новый риск ФЗ-38.
- **Referer подделывается**, Метрика видит только ссылки с переходами → авторитетна лишь
  краул-верификация.
- **Анкоров в API Вебмастера нет** (payload: `source_url`/`destination_url`/`discovery_date`) →
  anchor-профиль только фетчем донора; для мёртвых доноров анкор останется NULL → долю
  безанкорных считать по строкам с известным анкором, иначе метрика соврёт.
- **Донор-пул из `brand_source_url` шумный**: топ по покрытию — otzovik/irecommend/ozon/2gis/zoon.
  Без денилиста команда выдаст мусор; реальных редакционных хостов там десятки (be-in.ru,
  brandcatalog.ru, rubrikator.org, chikiriki.ru); vitrine.market — конкурент, к нему не ходить.
- **Два контура БД:** реестр живёт на Mac (там токены), воронка `brand_outreach` — на проде.
  Прод-таблиц для линкбилдинга не заводить (бейдж-эндпоинт БД не требует), иначе повторим
  класс граблей «истина в двух базах».
- **8 паразитных ссылок не трогать**: disavow в Яндексе нет, в Google не нужен.
