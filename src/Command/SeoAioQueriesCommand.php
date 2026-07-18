<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Regex-свип запросов GSC под AI Overviews (docs/seo_sitewide_backlog.md HIGH #2,
 * docs/drmax_seo_2026_digest.md §5, msg 1612/1614). Читает gsc_query_stats
 * (наполняется app:gsc:sync → syncQueryAnalytics), группирует запросы по формату
 * контента и помечает ожидаемый trigger rate AI Overviews по каждому формату.
 *
 * Trigger rate по группам — цифры EN-выборки Dr.Max (нет RU-замера, используем как
 * ориентир): baseline показов AIO ~21%; вопросы 57.9%; how-to/process ~58%
 * (максимальный zero-click риск); сравнения 26.2% (100% RAG в LLM); freshness —
 * 100%, но это единственная ЭКСПЕРИМЕНТАЛЬНО подтверждённая цифра, остальные —
 * наблюдения по выборке. best/top, commercial, conversational, other отдельно
 * не измерялись в дайджесте — берём baseline ~21% как консервативную оценку.
 *
 * Классификация эвристическая (регэкспы автора команды по описанию §5, дайджест
 * даёт только словесный список маркеров и мастер-regex, не готовый алгоритм) —
 * порядок групп ниже = приоритет при пересечении паттернов.
 *
 * RU-адаптация: мастер-regex дайджеста EN-центричный и якорит вопросное слово на
 * ^ (начало строки). По-русски маркер интента часто в КОНЦЕ/середине — прежде всего
 * доминирующий для каталога паттерн «{бренд} чей бренд». Поэтому добавлена группа
 * brand_entity (entity-вопросы «чей бренд / что за бренд / какой страны / кто
 * производитель»), а question ловит и неякорные «что это / это что / что значит».
 *
 * Только чтение, ничего не пишет.
 *
 *   php bin/console app:seo:aio-queries --limit=30
 */
#[AsCommand(
    name: 'app:seo:aio-queries',
    description: 'GSC gsc_query_stats: regex-свип запросов под AI Overviews + ожидаемый trigger rate по формату',
)]
class SeoAioQueriesCommand extends Command
{
    /** RU-мастер-regex дайджеста (§5) — используется только для доп. колонки «master», группировка ниже отдельная. */
    private const MASTER_REGEX_RU = '/^(что такое|что это|как|как сделать|как выбрать|почему|когда|где|кто|какой|лучший|топ|vs|сравн|разница между|обзор|альтернатив|руководств|гайд|чеклист|список|примеры|польза|преимущества|недостатки|цена|как исправить|для начинающих)/iu';

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

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Топ-N запросов в детальном списке', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $io->title('GSC · regex-свип запросов под AI Overviews');

        $exists = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'gsc_query_stats'",
        );
        if ($exists === 0) {
            $io->warning('Таблица gsc_query_stats ещё не создана — прогони миграцию.');
            return Command::SUCCESS;
        }

        $queries = $this->db->fetchAllAssociative(
            'SELECT query, SUM(impressions) impressions, SUM(clicks) clicks, AVG(position) position
             FROM gsc_query_stats
             GROUP BY query
             HAVING impressions > 0
             ORDER BY impressions DESC',
        );

        if ($queries === []) {
            $io->warning('gsc_query_stats пуста — запусти app:gsc:sync (без --analytics-only тоже сработает).');
            return Command::SUCCESS;
        }

        // Классификация + агрегаты по группе
        $groupTotals = [];
        foreach (self::GROUPS as $g) {
            $groupTotals[$g['name']] = ['label' => $g['label'], 'trigger' => $g['trigger'], 'queries' => 0, 'impressions' => 0, 'clicks' => 0];
        }
        $groupTotals['other'] = ['label' => 'Прочее', 'trigger' => 'baseline ~21% (не измерено)', 'queries' => 0, 'impressions' => 0, 'clicks' => 0];

        $classified = [];
        foreach ($queries as $row) {
            $group = $this->classify((string) $row['query']);
            $groupTotals[$group['name']]['queries']++;
            $groupTotals[$group['name']]['impressions'] += (int) $row['impressions'];
            $groupTotals[$group['name']]['clicks']      += (int) $row['clicks'];

            $classified[] = [
                'query'       => $row['query'],
                'group'       => $group['label'],
                'trigger'     => $group['trigger'],
                'master'      => preg_match(self::MASTER_REGEX_RU, (string) $row['query']) === 1 ? 'Y' : '',
                'impressions' => (int) $row['impressions'],
                'clicks'      => (int) $row['clicks'],
                'position'    => round((float) $row['position'], 1),
            ];
        }

        $io->section(sprintf('Всего уникальных запросов: %d', count($queries)));
        $summaryRows = [];
        foreach ($groupTotals as $t) {
            if ($t['queries'] === 0) {
                continue;
            }
            $summaryRows[] = [$t['label'], $t['queries'], $t['impressions'], $t['clicks'], $t['trigger']];
        }
        usort($summaryRows, static fn (array $a, array $b) => $b[2] <=> $a[2]);
        $io->table(['Формат', 'запросов', 'показы', 'клики', 'ожид. trigger rate AIO'], $summaryRows);

        $io->section(sprintf('Топ-%d запросов по показам', $limit));
        $top = array_slice($classified, 0, $limit);
        $io->table(
            ['Запрос', 'Формат', 'master', 'показы', 'клики', 'позиция', 'ожид. trigger rate'],
            array_map(
                static fn (array $r) => [mb_substr($r['query'], 0, 60), $r['group'], $r['master'], $r['impressions'], $r['clicks'], $r['position'], $r['trigger']],
                $top,
            ),
        );

        return Command::SUCCESS;
    }

    /** @return array{name:string,label:string,trigger:string} */
    private function classify(string $query): array
    {
        foreach (self::GROUPS as $g) {
            if (preg_match($g['pattern'], $query) === 1) {
                return $g;
            }
        }
        // Conversational: длинный разговорный запрос (9+ слов) — отдельный маркер §5, не regex по тексту.
        if (count(preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 9) {
            return ['name' => 'other', 'label' => 'Прочее', 'trigger' => 'не измерено (conversational, 9+ слов)'];
        }

        return ['name' => 'other', 'label' => 'Прочее', 'trigger' => 'baseline ~21% (не измерено)'];
    }
}
