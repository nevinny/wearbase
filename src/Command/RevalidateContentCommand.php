<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Service\ContentValidator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ре-валидация уже-`done` контента: прогоняет описание через ContentValidator::isRefusal
 * и демотирует протёкшие отказы (корпус-омоним / чужая сущность) в `review` + снимает с
 * публикации на проде. Нужна после ужесточения refusal-паттернов — выловить старые протечки
 * (кейс Mauritius: корпус про СТРАНУ Маврикий → модель отказала, но текст уехал на прод).
 *
 *   php bin/console app:brand:revalidate-content --dry-run   # только показать протечки
 *   php bin/console app:brand:revalidate-content             # демотировать + unpublish
 *   php bin/console app:brand:revalidate-content --id=3818   # один бренд
 */
#[AsCommand(
    name: 'app:brand:revalidate-content',
    description: 'Ре-валидация done-описаний через isRefusal → протёкшие отказы в review + unpublish с прода',
)]
class RevalidateContentCommand extends Command
{
    private int $checked = 0;
    private int $demoted = 0;
    private int $unpublished = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly ContentValidator $validator,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
        #[Autowire('%env(default::AGENT_API_SECRET)%')]
        private readonly ?string $agentSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать протёкшие отказы, ничего не менять')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Проверить один бренд по ID')
            ->addOption('no-unpublish', null, InputOption::VALUE_NONE, 'Демотировать в review, но НЕ снимать с прода');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $noUnpub   = (bool) $input->getOption('no-unpublish');
        $brandId   = $input->getOption('id');

        $io->title('Ре-валидация done-контента (isRefusal)');
        if ($dryRun) {
            $io->note('dry-run — изменения не сохраняются, прод не трогаем');
        }

        // Берём только id+slug — описание тянем по одному (память на 2000+ брендах).
        $sql = "SELECT b.id, b.slug FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
                WHERE p.status='done' AND b.description IS NOT NULL AND b.description<>''";
        $params = [];
        if ($brandId !== null) {
            $sql .= ' AND b.id = :id';
            $params['id'] = (int) $brandId;
        }
        $rows = $this->db->fetchAllAssociative($sql, $params);
        $io->section(sprintf('Брендов к проверке: %d', count($rows)));

        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $slug = (string) $row['slug'];
            $desc = (string) $this->db->fetchOne('SELECT description FROM brand WHERE id = :id', ['id' => $id]);
            $this->checked++;

            if (!$this->validator->isRefusal($desc)) {
                continue;
            }

            $title = (string) $this->db->fetchOne('SELECT title FROM brand WHERE id = :id', ['id' => $id]);
            $io->writeln(sprintf('  ⚠ #%d %s — отказ протёк: «%s…»', $id, $title, mb_substr(trim($desc), 0, 80)));
            $this->demoted++;

            if ($dryRun) {
                continue;
            }

            // Демот в review (manual-верификация); status=done блокировался бы повторным пушем.
            $pipeline = $this->em->getRepository(BrandRagPipeline::class)->getOrCreate($this->em->find(Brand::class, $id));
            $pipeline->setStatus(BrandRagPipeline::STATUS_REVIEW)
                ->setLastError('revalidate: refusal протёк (корпус не о бренде)');
            $this->em->flush();
            $this->em->clear();

            if (!$noUnpub) {
                [$ok, $msg] = $this->unpublishOnProd($slug, $id);
                $io->writeln('    ' . $msg);
                if ($ok) {
                    $this->unpublished++;
                }
            }
        }

        $io->newLine();
        $io->table(['Метрика', 'Значение'], [
            ['Проверено', $this->checked],
            ['Отказов протекло → review', $this->demoted],
            ['Снято с прода', $this->unpublished],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Снятие с публикации на проде — агент-API /api/v1/brands/unpublish (X-Agent-Token + HMAC),
     * как в RagDashboardController::unpublishOnProd / PushBrandsCommand. Fail-soft.
     *
     * @return array{0:bool,1:string} [успех, сообщение]
     */
    private function unpublishOnProd(string $slug, int $id): array
    {
        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->agentToken) === '' || trim((string) $this->agentSecret) === '') {
            return [false, "прод-API не настроен — #{$id} НЕ снят (только локально review)"];
        }
        if ($slug === '') {
            return [false, "#{$id} без slug — на проде не снять"];
        }

        try {
            $body = json_encode(['slug' => $slug], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $data = $this->httpClient->request('POST', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/brands/unpublish', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Agent-Token' => (string) $this->agentToken,
                    'X-Signature'   => hash_hmac('sha256', $body, (string) $this->agentSecret),
                ],
                'body'    => $body,
                'timeout' => 8,
            ])->toArray(false);

            return match ($data['status'] ?? null) {
                'unpublished' => [true,  "✓ #{$id} снят с прод-каталога"],
                'not_found'   => [false, "#{$id} не найден на проде (не публиковался)"],
                default       => [false, "#{$id} — неожиданный ответ прода"],
            };
        } catch (\Throwable $e) {
            return [false, "прод недоступен ({$e->getMessage()}) — #{$id} НЕ снят (только локально)"];
        }
    }
}
