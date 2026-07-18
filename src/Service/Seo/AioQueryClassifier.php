<?php

declare(strict_types=1);

namespace App\Service\Seo;

/**
 * Классификатор поисковых запросов по формату контента под AI Overviews
 * (docs/drmax_seo_2026_digest.md §5, msg 1612/1614). Единый источник правды для
 * app:seo:aio-queries (полный свип) и утреннего дайджеста (топ AIO-утечки).
 *
 * Trigger rate по группам — цифры EN-выборки Dr.Max как ориентир (RU-замера нет):
 * baseline ~21%; вопросы 57.9%; how-to ~58%; сравнения 26.2%; freshness — 100%
 * (единственная экспериментально подтверждённая). brand_entity/best/commercial —
 * не измерялись, baseline ~21% (brand_entity — оценка на уровне вопросов).
 *
 * RU-адаптация: мастер-regex дайджеста EN-центричный, якорит вопросное слово на ^.
 * По-русски маркер интента часто в КОНЦЕ/середине — прежде всего доминирующий для
 * каталога паттерн «{бренд} чей бренд». Отсюда группа brand_entity + неякорные
 * маркеры в question. Порядок групп = приоритет (первое совпадение побеждает).
 */
final class AioQueryClassifier
{
    /** RU-мастер-regex дайджеста (§5) — для доп. колонки «master»; группировка ниже отдельная. */
    public const MASTER_REGEX_RU = '/^(что такое|что это|как|как сделать|как выбрать|почему|когда|где|кто|какой|лучший|топ|vs|сравн|разница между|обзор|альтернатив|руководств|гайд|чеклист|список|примеры|польза|преимущества|недостатки|цена|как исправить|для начинающих)/iu';

    /** Группы с высокой вероятностью AI Overviews (не baseline «прочее»/commercial/best_top). */
    public const AIO_LIKELY = ['freshness', 'howto', 'comparison', 'brand_entity', 'question'];

    /**
     * Порядок = приоритет (первое совпадение побеждает).
     * @var array<int,array{name:string,label:string,pattern:string,trigger:string}>
     */
    private const GROUPS = [
        [
            'name'    => 'freshness',
            'label'   => 'Свежесть (года/актуальность)',
            'pattern' => '/\b20(2[4-9]|3\d)\b|актуальн\w*|нов[иы][нй]к\w*|свеж\w*|обновлен\w*/iu',
            'trigger' => '100% (эксперим. подтв., §5)',
        ],
        [
            'name'    => 'howto',
            'label'   => 'How-to / процесс',
            'pattern' => '/как\s+(сделать|выбрать|исправить|настроить|подобрать|установить)|руководств\w*|гайд\b|чек-?лист\w*|инструкц\w*|для начинающих/iu',
            'trigger' => '~58% (max zero-click риск)',
        ],
        [
            'name'    => 'comparison',
            'label'   => 'Сравнение',
            'pattern' => '/\bvs\b| или \b|сравн\w*|разниц\w*\s+между|отличи\w*\s+(от|между)/iu',
            'trigger' => '26.2% (100% RAG в LLM)',
        ],
        [
            // Доминирующий RU-паттерн каталога: «{бренд} чей бренд», «что за бренд X»,
            // «X какой страны», «кто производитель X». Маркер интента стоит в КОНЦЕ/середине,
            // поэтому ^-якорный `question` его не ловит. Это definitional entity-вопросы —
            // как раз то, что AI Overviews отвечают напрямую. Trigger не замерен в дайджесте,
            // ставим уровень вопросов (§5) как обоснованную оценку.
            'name'    => 'brand_entity',
            'label'   => 'Бренд/сущность («чей бренд»)',
            'pattern' => '/чь[яеёи]\s+(бренд\w*|марк\w*|фирм\w*)|че[йм]\s+бренд\w*|что\s+за\s+(бренд\w*|марк\w*|фирм\w*)|как(ой|ая|ого|ому)\s+(стран\w*|фирм\w*|компани\w*)|(бренд\w*|марк\w*)\s+как(ой|ая)\s+стран\w*|откуда\s+(этот\s+)?(бренд\w*|марк\w*)|кто\s+(производ\w*|выпуска\w*|владе\w*|создал)|страна[- ]?производител\w*/iu',
            'trigger' => '≈57.9% (entity-вопрос, RU — оценка)',
        ],
        [
            // ^-якорные вопросные слова (начало) + неякорные RU-маркеры в любой позиции
            // («что это», «это что», «что значит/означает»).
            'name'    => 'question',
            'label'   => 'Вопрос / определение',
            'pattern' => '/^(что такое|что это|как|почему|когда|где|кто|как[ао]й|какая|какое|какие|какого|какую)\b|\b(что это|это что|что значит|что означает|как расшифров\w*)\b/iu',
            'trigger' => '57.9% (66.5% всех AIO — вопросы)',
        ],
        [
            'name'    => 'best_top',
            'label'   => 'Best/Top/рейтинг',
            'pattern' => '/лучш\w*|топ[- ]?\d*\b|рейтинг\w*/iu',
            'trigger' => 'не измерено — baseline ~21%',
        ],
        [
            'name'    => 'commercial',
            'label'   => 'Коммерческий',
            'pattern' => '/цена|стоимост\w*|купить|заказать|сколько стоит/iu',
            'trigger' => 'не измерено — baseline ~21%',
        ],
    ];

    /** @return array<int,array{name:string,label:string,pattern:string,trigger:string}> */
    public function groups(): array
    {
        return self::GROUPS;
    }

    /** @return array{name:string,label:string,trigger:string} */
    public function classify(string $query): array
    {
        foreach (self::GROUPS as $g) {
            if (preg_match($g['pattern'], $query) === 1) {
                return ['name' => $g['name'], 'label' => $g['label'], 'trigger' => $g['trigger']];
            }
        }
        // Conversational: длинный разговорный запрос (9+ слов) — отдельный маркер §5, не regex по тексту.
        if (count(preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 9) {
            return ['name' => 'other', 'label' => 'Прочее', 'trigger' => 'не измерено (conversational, 9+ слов)'];
        }

        return ['name' => 'other', 'label' => 'Прочее', 'trigger' => 'baseline ~21% (не измерено)'];
    }

    public function isAioLikely(string $query): bool
    {
        return in_array($this->classify($query)['name'], self::AIO_LIKELY, true);
    }

    public function matchesMaster(string $query): bool
    {
        return preg_match(self::MASTER_REGEX_RU, $query) === 1;
    }
}
