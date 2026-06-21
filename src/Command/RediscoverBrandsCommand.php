<?php

namespace App\Command;

use App\Entity\BrandRagPipeline;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Сброс брендов с мусорным/мёртвым набором источников обратно в discover.
 *
 * Кейс: discover-инцидент (SearXNG CAPTCHA 2026-06-04..08) нагенерил несуществующие
 * домены (NXDOMAIN) → fetch их «отработал» без текста → source_count=0 → deferred
 * навсегда (очередь deferred не перебирает). Триаж: 188/188 URL → http_status NULL.
 *
 * Действие: физически чистим brand_source_url (мусор) + сбрасываем pipeline в
 * pre-discover (discoveredAt=NULL, status=pending, обнуляем стадии) → discover
 * (ПЕРВИЧНЫЙ Yandex Search API) переоткрывает источники начисто.
 *
 *   php bin/console app:brand:rediscover --id=42 --dry-run
 *   php bin/console app:brand:rediscover 200 --no-debug          # пачка из deferred+source_count=0
 */
#[AsCommand(
    name: 'app:brand:rediscover',
    description: 'Сброс брендов с мёртвыми/мусорными источниками обратно в discover (Yandex-первичный)',
)]
class RediscoverBrandsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 50)
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Один или несколько ID через запятую')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что будет сброшено, без изменений')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (($idOpt = $input->getOption('id')) !== null) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $idOpt))));
        } else {
            // Застрявшие из-за мусорных источников: deferred + нет корпуса.
            $limit = max(1, (int) $input->getArgument('limit'));
            $ids = array_map('intval', $this->db->fetchFirstColumn(
                "SELECT brand_id FROM brand_rag_pipeline WHERE status = :s AND source_count = 0 ORDER BY brand_id LIMIT {$limit}",
                ['s' => BrandRagPipeline::STATUS_DEFERRED]
            ));
        }

        if ($ids === []) {
            $io->success('Нет брендов на пересборку.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('RAG · сброс в discover: %d брендов%s', count($ids), $dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT b.id, b.title, (SELECT COUNT(*) FROM brand_source_url WHERE brand_id = b.id) urls
                 FROM brand b WHERE b.id IN (?)',
                [$ids],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
            foreach ($rows as $r) {
                $io->text(sprintf('  #%d %s — пометить %d URL skipped, сброс в discover', $r['id'], $r['title'] ?? '—', $r['urls']));
            }
            return Command::SUCCESS;
        }

        // 1) Мусорные источники НЕ удаляем — помечаем inactive (skipped). Остаются в БД:
        //    уникальный ключ (brand_id, url_hash) не даст discover повторно их добавить;
        //    fetch/crawl берут только pending → пропустят; в embed не попадут (документа нет).
        $skippedUrls = $this->db->executeStatement(
            "UPDATE brand_source_url SET status = 'skipped' WHERE brand_id IN (?) AND status <> 'skipped'",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        // 2) Сброс pipeline в pre-discover → findForDiscover заберёт по discovered_at IS NULL.
        // Статус — хардкод-константа (не пользовательский ввод) → безопасно инлайнить, чтобы
        // не смешивать named/positional/array-биндинги в одном запросе.
        $pending = BrandRagPipeline::STATUS_PENDING;
        $reset = $this->db->executeStatement(
            "UPDATE brand_rag_pipeline SET status = '{$pending}', discovered_at = NULL, scraped_at = NULL,
                embedded_at = NULL, generated_at = NULL, source_count = 0,
                scrape_attempts = 0, embed_attempts = 0, generate_attempts = 0,
                crawl_status = NULL, crawled_at = NULL, last_error = NULL
             WHERE brand_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $io->success(sprintf(
            'Сброшено %d брендов в discover (%d мусорных URL → skipped, остаются в БД для дедупа). Заберёт app:brand:discover (Yandex-первичный).',
            $reset, $skippedUrls
        ));

        return Command::SUCCESS;
    }
}
