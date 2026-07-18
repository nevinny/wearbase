<?php

namespace App\Command;

use App\Service\Seo\AioQueryClassifier;
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
 * Классификация — в App\Service\Seo\AioQueryClassifier (единый источник правды,
 * тот же сервис использует утренний дайджест). Только чтение, ничего не пишет.
 *
 *   php bin/console app:seo:aio-queries --limit=30
 */
#[AsCommand(
    name: 'app:seo:aio-queries',
    description: 'GSC gsc_query_stats: regex-свип запросов под AI Overviews + ожидаемый trigger rate по формату',
)]
class SeoAioQueriesCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AioQueryClassifier $classifier,
    ) {
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
            $io->warning('gsc_query_stats пуста — запусти app:gsc:sync.');
            return Command::SUCCESS;
        }

        // Классификация + агрегаты по группе
        $groupTotals = [];
        foreach ($this->classifier->groups() as $g) {
            $groupTotals[$g['name']] = ['label' => $g['label'], 'trigger' => $g['trigger'], 'queries' => 0, 'impressions' => 0, 'clicks' => 0];
        }
        $groupTotals['other'] = ['label' => 'Прочее', 'trigger' => 'baseline ~21% (не измерено)', 'queries' => 0, 'impressions' => 0, 'clicks' => 0];

        $classified = [];
        foreach ($queries as $row) {
            $group = $this->classifier->classify((string) $row['query']);
            $groupTotals[$group['name']]['queries']++;
            $groupTotals[$group['name']]['impressions'] += (int) $row['impressions'];
            $groupTotals[$group['name']]['clicks']      += (int) $row['clicks'];

            $classified[] = [
                'query'       => $row['query'],
                'group'       => $group['label'],
                'trigger'     => $group['trigger'],
                'master'      => $this->classifier->matchesMaster((string) $row['query']) ? 'Y' : '',
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
}
