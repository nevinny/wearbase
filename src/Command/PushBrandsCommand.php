<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Repository\BrandRagPipelineRepository;
use App\Service\Agent\BrandPayloadAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Агент-генератор: пушит ГОТОВЫЕ бренды (isPublishReady: done + meta + FAQ + keywords)
 * на прод через /api/v1/brands/upsert (X-Agent-Token + HMAC-подпись тела).
 * На проде бренд приземляется как status=new + publish_pending=1 и ждёт дрип-крон.
 *
 * Трекинг доставки: pipeline.pushed_at (успех) / push_attempts+push_error (ретраи ≤3).
 * content_version инкрементируется на dev после успешной доставки.
 *
 *   php bin/console app:brand:push --id=42 --dry-run    # показать payload без отправки
 *   php bin/console app:brand:push 10 --no-debug        # сетевая стадия демона
 */
#[AsCommand(
    name: 'app:brand:push',
    description: 'Агент: доставка готовых брендов на прод (/api/v1/brands/upsert)',
)]
class PushBrandsCommand extends Command
{
    private const TIMEOUT_SEC = 60;

    private int $pushed  = 0;
    private int $skipped = 0;   // прод ответил skipped (версия не новее)
    private int $failed  = 0;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry        $managerRegistry,
        private readonly HttpClientInterface    $httpClient,
        private readonly BrandPayloadAssembler  $assembler,
        private readonly ?string                $prodApiUrl,
        private readonly ?string                $apiToken,
        private readonly ?string                $apiSecret,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за запуск', 50)
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Один бренд по ID (без проверки готовности)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать payload, не отправлять')
            ->addOption('shard',   null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1)', '0')
            ->addOption('total',   null, InputOption::VALUE_REQUIRED, 'Всего шардов', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = (int) $input->getArgument('limit');
        $brandId = $input->getOption('id');
        $dryRun  = (bool) $input->getOption('dry-run');
        $shard   = (int) $input->getOption('shard');
        $total   = max(1, (int) $input->getOption('total'));

        $io->title('Агент · доставка брендов на прод');
        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->apiToken) === '' || trim((string) $this->apiSecret) === '') {
            $io->error('Не заданы PROD_API_URL / AGENT_API_TOKEN / AGENT_API_SECRET в .env.local');
            return Command::FAILURE;
        }
        $io->text("Прод: {$this->prodApiUrl}");
        if ($dryRun) {
            $io->note('dry-run — без отправки');
        }

        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        /** @var \App\Repository\BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandIds = array_map(
            static fn(Brand $b) => $b->getId(),
            $repo->findReadyToPush($limit, $shard, $total),
        );

        if ($brandIds === []) {
            $io->success('Нет готовых брендов к доставке.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к доставке: %d (shard %d/%d)', count($brandIds), $shard, $total));
        $this->em->clear();
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            $brand = $this->em->find(Brand::class, $id);
            if ($brand) {
                $this->processBrand($brand, $io, $dryRun);
            }
            $io->progressAdvance();
            gc_collect_cycles(); // после em->clear() циклические ссылки Doctrine иначе текут
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";

        try {
            $payload = $this->assembler->assemble($brand);

            if ($dryRun) {
                $io->text(sprintf(
                    '  → %s: v%d, faq=%d, kw=%d, links=%d, logo=%s',
                    $name,
                    $payload['content_version'],
                    count($payload['faq']),
                    count($payload['keywords']),
                    count($payload['links']),
                    isset($payload['logo']) ? 'да' : 'нет',
                ));
                return;
            }

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $response = $this->httpClient->request('POST', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/brands/upsert', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Agent-Token' => (string) $this->apiToken,
                    'X-Signature'   => hash_hmac('sha256', $body, (string) $this->apiSecret),
                ],
                'body'    => $body,
                'timeout' => self::TIMEOUT_SEC,
            ]);

            $status = $response->getStatusCode();
            $data   = $status === 200 ? $response->toArray(false) : [];

            if ($status !== 200 || !in_array($data['status'] ?? '', ['created', 'updated', 'skipped'], true)) {
                throw new \RuntimeException(sprintf('HTTP %d: %s', $status, mb_substr($response->getContent(false), 0, 300)));
            }

            $io->text(sprintf('  → %s: %s (prod id %s)', $name, $data['status'], $data['brand_id'] ?? '?'));

            // Успех: pushed_at + инкремент версии на dev (следующий пуш будет v+1)
            $this->markPushed($brand, (int) $payload['content_version']);
            $data['status'] === 'skipped' ? $this->skipped++ : $this->pushed++;
        } catch (\Throwable $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));
            $this->failed++;
            $this->recordFailure($brand->getId(), $e->getMessage(), $dryRun);
        }
    }

    private function markPushed(Brand $brand, int $sentVersion): void
    {
        $brand->setContentVersion($sentVersion);
        /** @var BrandRagPipelineRepository $repo */
        $repo = $this->em->getRepository(BrandRagPipeline::class);
        $repo->getOrCreate($brand)
            ->setPushedAt(new \DateTime())
            ->setPushError(null);
        $this->em->flush();
        $this->em->clear();
    }

    private function recordFailure(?int $brandId, string $error, bool $dryRun): void
    {
        if ($brandId === null || $dryRun) {
            return;
        }
        try {
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            } else {
                $this->em->clear();
            }
            $brand = $this->em->find(Brand::class, $brandId);
            if ($brand) {
                /** @var BrandRagPipelineRepository $repo */
                $repo = $this->em->getRepository(BrandRagPipeline::class);
                $p = $repo->getOrCreate($brand);
                $p->setPushAttempts($p->getPushAttempts() + 1)
                    ->setPushError(mb_substr($error, 0, 2000));
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
            // батч продолжается
        }
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Доставлено',            $this->pushed],
            ['Пропущено (версия)',    $this->skipped],
            ['Ошибок',                $this->failed],
        ]);
    }
}
