# Петля экспериментов над механиками

> Системный контур «гипотеза → изменение механики → замер → вывод»: сайт улучшает себя не
> только генерацией текстов, но и проверкой поведенческих правок (один CTA вместо нескольких,
> порядок блоков, подборки вместо фильтров). MVP, построен 2026-07-19.
> Родственный контур для КОНТЕНТА — [aio_remediation.md](aio_remediation.md) (detect→gate→замер→откат
> на `brand_content_revision`); здесь тот же паттерн замера, но единица — правка МЕХАНИКИ.

## Зачем именно механики

Контент-петля (`evaluate-experiments`) уже улучшает тексты брендов. Но конверсия и вовлечение
чаще упираются в поведение страницы, а не в текст. Гипотезы механик взяты из нейроэкономики
([klyucharev_decisions_2026.md](klyucharev_decisions_2026.md) «Второй проход»): OFC-паралич от избытка
опций → один CTA / 3 подборки; in-group-конформизм → «бренды вашего уровня»; гарантии доверия на
первый экран. Петля не внедряет правку сама (осознанная граница MVP) — она **фиксирует что/где/когда
менялось и меряет эффект**, а Twig-правку вносит владелец/сессия.

## Контур и статус-машина

```
proposed ──(--start, внесена правка)──> running ──(ends_at ≤ now)──> measured ──> adopted | rolled_back
   ▲                                                                                   │
   │ app:experiment:propose (пн, ICE-выбор, человек-гейт TG)                           │
   └─── правку adopt/rollback владелец вносит руками; команда лишь фиксирует статус ────┘
```

| Шаг | Команда | Крон (Mac) | Что делает |
|---|---|---|---|
| Предложить | `app:experiment:propose` | пн 10:20 | Из бэклога гипотез отбрасывает уже заведённые (по `code`), берёт ОДНУ с макс. ICE, заводит `status=proposed`, шлёт в TG гипотезу + команду запуска |
| Запустить | `app:experiment:propose --start=<id>` | вручную | `proposed→running`, снимает baseline когорт A/B за окно ДО старта, ставит `ends_at = now + period_days` |
| Замерить | `app:experiment:evaluate` | ежедн. 10:20 | Для `running` с истёкшим окном считает DiD когорт A/B, пишет `result_json`, `status=measured`, шлёт сводку с рекомендацией adopt/rollback |
| Зафиксировать | `app:experiment:evaluate --adopt=<id>` / `--rollback=<id>` | вручную | `measured→adopted`/`rolled_back` (владелец параллельно оставляет/убирает правку) |

**Человек-гейт — не inline-кнопки, а команда в TG.** Причина та же, что в [aio_remediation.md](aio_remediation.md):
TG-вебхук живёт на проде, а строки экспериментов — в Mac-БД, прод их не видит → тап работать не может.
Честный MVP: TG-сообщение с готовой командой, запуск руками после внесения правки механики.

## Diff-in-diff (почему две когорты)

Замер сравнивает не «до/после» одной группы (это ловит сезонность), а **DiD**:

```
DiD = (A_after − A_before) − (B_after − B_before)
```

- **A (вариант)** — где механика применена.
- **B (контроль/holdout)** — где НЕ применена; её дельта = чистая сезонность/общий тренд, которую вычитаем.

Два стиля когорт (JSON-дескриптор, резолвится `CohortMetricProbe` в SQL-предикат, без обращения к таблице `brand`):
- **card-механики** (правка карточки бренда) → 50/50 holdout по чётности id: `{"kind":"brand_parity","parity":0}` vs `parity:1`. Владелец гейтит Twig условием `brand.id % 2 == 0`.
- **hub-механики** → «затронутый тип страниц vs незатронутый как сезонный контроль»: `{"kind":"page_like","like":"%/style/%"}` (вариант) vs `%/city/%` (контроль).
- Точечно: `{"kind":"brand_ids","ids":[..]}`.

Метрики (`CohortMetricProbe::METRICS`, источники cohort-атрибутируемы):
`card_conversion` (= клики /go/ ÷ показы), `search_ctr` (= клики ÷ показы GSC), `outbound_clicks`, `clicks`, `impressions`.
Данные: `gsc_page_stats` (дедуплена по (page,day)) + `brand_outbound_click`.

**Пороги вердикта** (`ExperimentEvaluateCommand`): суммарно показов A+B < 30 → `inconclusive`;
относительный DiD к baseline A ≥ +10% → `adopt`, ≤ −10% → `rollback`, иначе `inconclusive`.
Гейт свежести: `gsc_page_stats` пуста/протухла (>5 дней)/отсутствует (env пуст в test) → graceful no-op,
не судим по нулям (иначе ложный rollback).

## Как добавить гипотезу

Дописать элемент в `MechanicExperimentBacklog::all()` (`src/Service/Experiment/`) с уникальным `code`,
метрикой из `CohortMetricProbe::METRICS` и когортами A/B. Ближайший понедельник `propose` подхватит
новый `code` (идемпотентно — дважды одно и то же не предложит).

## Как читать результат

`mechanic_experiment.result_json`:
```json
{
  "period_days": 21,
  "metric": "card_conversion",
  "baseline": {"a": {"value": 0.05, "impr": 100, "clicks": 5, "outbound": 5}, "b": {...}},
  "after":    {"a": {"value": 0.20, ...}, "b": {...}},
  "did": 0.14,          // (a_after−a_before) − (b_after−b_before)
  "rel": 2.8,           // did ÷ baseline_a (доля)
  "recommendation": "adopt"   // adopt | rollback | inconclusive
}
```
`adopt` — правка сработала, оставить; `rollback` — просела, убрать; `inconclusive` — выборки/сигнала мало,
продлить/повторить. Финальную правку вносит человек, статус фиксирует `--adopt`/`--rollback`.

## Файлы

`src/Entity/MechanicExperiment.php` · `src/Repository/MechanicExperimentRepository.php` ·
`src/Service/Experiment/{MechanicExperimentBacklog,CohortMetricProbe}.php` ·
`src/Command/{ExperimentProposeCommand,ExperimentEvaluateCommand}.php` ·
миграции `Version20260719_mechanic_experiment.php`, `Version20260719_attach_experiment_cron.php` ·
тест `tests/Command/MechanicExperimentLoopTest.php`.

## Стартовый бэклог (ICE = I·C·E)

| code | гипотеза | метрика | ICE |
|---|---|---|---|
| `single_cta` | один CTA вместо нескольких на карточке | card_conversion | **336** |
| `zero_commission_hero` | гарантия «0% комиссии» на первый экран карточки | card_conversion | 252 |
| `similar_level_top` | блок «похожие бренды вашего уровня» выше | card_conversion | 180 |
| `three_collections` | 3 подборки вместо веера фильтров в хабах | search_ctr | 175 |
| `new_brands_counter` | счётчик «+N брендов за неделю» в хабах | search_ctr | 175 |
| `similar_brands_hub` | «бренды вашего уровня уже здесь» в хабах | search_ctr | 150 |
| `trust_guarantee_hub` | гарантия доверия на первый экран хабов | search_ctr | 120 |

Первым `propose` предлагает **`single_cta`** (ICE 336).
