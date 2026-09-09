<?php

namespace App\Command;

use App\Service\Keyword\KeywordNicheClassifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Классификатор ниши по ФРАЗАМ brand_keyword (не по бренду — см.
 * app:brand:niche-check). Импорт лидов и Wordstat подмешивают мусорные
 * related-запросы («яндекс погода») даже у нишевых брендов — эта команда
 * помечает такие фразы niche_status='off', остальные потребители контента
 * (findByBrandRanked/findTopByBrand, агент-payload) их режут. NULL остаётся
 * fail-open — трактуется как проходящее нишу.
 *
 * Шаги: 1) пропагация уже известных вердиктов на одинаковые фразы других
 * брендов (без LLM); 2) LLM-классификация оставшихся NULL-фраз чанками.
 *
 *   php -d memory_limit=512M bin/console app:brand:keyword-niche-check 500 --no-debug
 */
#[AsCommand(
    name: 'app:brand:keyword-niche-check',
    description: 'Классифицировать фразы brand_keyword на принадлежность нише (мода+красота)',
)]
class CheckKeywordNicheCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly KeywordNicheClassifier $classifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Сколько уникальных фраз классифицировать за прогон', 500)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Переклассифицировать все фразы (игнорировать уже проверенные)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не записывать — только показать сводку');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $force   = (bool) $input->getOption('force');
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = max(1, (int) $input->getArgument('limit'));

        if (!$force) {
            $propagated = $this->propagateKnownVerdicts($dryRun);
            $io->writeln(sprintf('Пропагировано без LLM: %d строк.', $propagated));
        }

        $keywords = $this->selectKeywordsToClassify($limit, $force);
        if ($keywords === []) {
            $io->success('Нечего классифицировать — все фразы уже проверены.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Классификация ниши: %d уникальных фраз%s', count($keywords), $dryRun ? ' (dry-run)' : ''));

        $verdicts = $this->classifier->classifyBatch($keywords);
        $in = $off = 0;
        $skipped = count($keywords) - count($verdicts);

        foreach ($keywords as $keyword) {
            $verdict = $verdicts[$keyword] ?? null;
            if ($verdict === null) {
                continue;
            }

            $tag = $verdict === 'off' ? '<fg=red>off</>' : '<fg=green>in </>';
            $io->writeln(sprintf('  %s %s', $tag, $keyword));
            $verdict === 'off' ? $off++ : $in++;

            if (!$dryRun) {
                $this->applyVerdict($keyword, $verdict, $force);
            }
        }

        $remaining = (int) $this->db->fetchOne('SELECT COUNT(DISTINCT keyword) FROM brand_keyword WHERE niche_status IS NULL');

        $io->newLine();
        $io->table(
            ['обработано', 'in', 'off', 'пропущено (LLM недоступна/не распарсилось)', 'осталось NULL'],
            [[count($keywords), $in, $off, $skipped, $remaining]],
        );

        return Command::SUCCESS;
    }

    /** Копирует уже известные вердикты на строки той же фразы с niche_status IS NULL. */
    private function propagateKnownVerdicts(bool $dryRun): int
    {
        if ($dryRun) {
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM brand_keyword t JOIN (SELECT keyword, MIN(niche_status) ns FROM brand_keyword WHERE niche_status IS NOT NULL GROUP BY keyword) s ON t.keyword = s.keyword WHERE t.niche_status IS NULL',
            );
        }

        return $this->db->executeStatement(
            'UPDATE brand_keyword t JOIN (SELECT keyword, MIN(niche_status) ns FROM brand_keyword WHERE niche_status IS NOT NULL GROUP BY keyword) s ON t.keyword = s.keyword SET t.niche_status = s.ns, t.niche_checked_at = NOW() WHERE t.niche_status IS NULL',
        );
    }

    /** @return string[] */
    private function selectKeywordsToClassify(int $limit, bool $force): array
    {
        $sql = $force
            ? 'SELECT DISTINCT keyword FROM brand_keyword LIMIT ' . $limit
            : 'SELECT DISTINCT keyword FROM brand_keyword WHERE niche_status IS NULL LIMIT ' . $limit;

        return $this->db->fetchFirstColumn($sql);
    }

    private function applyVerdict(string $keyword, string $verdict, bool $force): void
    {
        $sql = $force
            ? 'UPDATE brand_keyword SET niche_status = :v, niche_checked_at = NOW() WHERE keyword = :kw'
            : 'UPDATE brand_keyword SET niche_status = :v, niche_checked_at = NOW() WHERE keyword = :kw AND niche_status IS NULL';

        $this->db->executeStatement($sql, ['v' => $verdict, 'kw' => $keyword]);
    }
}
