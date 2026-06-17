<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandRagPipeline;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconcile брендов, застрявших в `embedded` из-за лоссового демоута `done → embedded`
 * (ре-эмбед уже сгенерированного контента — инцидент 06-06, 484 бренда). У таких есть
 * `generated_at` + непустое описание: их контент готов, статус надо вернуть в `done`,
 * иначе generate их не подберёт (описание непустое → невидим для findWithoutDescription)
 * и дренаж стоит.
 *
 * Статус-only восстановление: контент (description/meta) уже в brand.* и в
 * brand_content_revision (аудит при генерации) — versioner не нужен, контент не меняем.
 *
 *   php bin/console app:rag:reconcile-stuck --dry-run   # показать когорты
 *   php bin/console app:rag:reconcile-stuck             # восстановить демотнутых done
 */
#[AsCommand(
    name: 'app:rag:reconcile-stuck',
    description: 'Восстановить статус done у брендов, демотнутых ре-эмбедом в embedded (чинит дренаж)',
)]
class ReconcileStuckPipelineCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать когорты, ничего не менять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Reconcile застрявших в embedded');
        if ($dryRun) {
            $io->note('dry-run — ничего не меняем');
        }

        // Когорты внутри embedded — понять, кого восстанавливаем, а кого оставляем генерации.
        $cohorts = $this->db->fetchAllAssociative(
            "SELECT
               CASE
                 WHEN p.generated_at IS NOT NULL AND p.embedded_at > p.generated_at
                      THEN '1. демотнут done→embedded (ре-эмбед) → восстановить'
                 WHEN p.generated_at IS NOT NULL
                      THEN '2. сгенерирован, не done (прочее) → восстановить'
                 WHEN b.description IS NOT NULL AND CHAR_LENGTH(b.description) >= 400
                      THEN '3. legacy-описание, не генерировался → ждёт генерации'
                 ELSE '4. тонкий/пустой → ждёт генерации'
               END AS cohort,
               COUNT(*) AS c
             FROM brand_rag_pipeline p
             JOIN brand b ON b.id = p.brand_id
             WHERE p.status = :embedded
             GROUP BY cohort
             ORDER BY cohort",
            ['embedded' => BrandRagPipeline::STATUS_EMBEDDED],
        );

        $rows = array_map(static fn(array $r): array => [$r['cohort'], $r['c']], $cohorts);
        $io->section('Когорты embedded');
        $io->table(['Когорта', 'Брендов'], $rows);

        // Восстанавливаем: embedded + есть generated_at + непустое описание = готовый контент,
        // демотнутый ре-эмбедом. Возвращаем done (push сам решит ре-доставку по pushed_at).
        $sql = "UPDATE brand_rag_pipeline p JOIN brand b ON b.id = p.brand_id
                SET p.status = :done
                WHERE p.status = :embedded
                  AND p.generated_at IS NOT NULL
                  AND b.description IS NOT NULL AND b.description <> ''";
        $params = ['done' => BrandRagPipeline::STATUS_DONE, 'embedded' => BrandRagPipeline::STATUS_EMBEDDED];

        if ($dryRun) {
            $would = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM brand_rag_pipeline p JOIN brand b ON b.id = p.brand_id
                 WHERE p.status = :embedded AND p.generated_at IS NOT NULL
                   AND b.description IS NOT NULL AND b.description <> ''",
                ['embedded' => BrandRagPipeline::STATUS_EMBEDDED],
            );
            $io->success("Было бы восстановлено в done: {$would}");
            return Command::SUCCESS;
        }

        $restored = (int) $this->db->executeStatement($sql, $params);
        $io->success("Восстановлено в done: {$restored}");

        return Command::SUCCESS;
    }
}
