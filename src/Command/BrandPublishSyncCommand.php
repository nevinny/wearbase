<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Только Mac. Прод-дрип (app:brand:publish-tick) флипает публикацию НА ПРОДЕ,
 * назад в Mac это не зеркалится — Mac-статусы/`published_at` со временем врут.
 * Команда тянет с прода реально опубликованные бренды (agent-API
 * GET /api/v1/brands/published) и приводит Mac в соответствие через доменный
 * `Brand::publish($prodPublishedAt)` — по slug (ключ сопоставления prod↔dev,
 * id разные autoincrement'ы).
 *
 * Обратной синхронизации (снятие с публикации) НЕТ — если бренда нет в прод-ответе,
 * Mac не трогаем, это не задача команды.
 */
#[AsCommand(
    name: 'app:brand:publish-sync',
    description: 'Зеркалирование статуса публикации прод→Mac (что реально вывел дрип)',
)]
class BrandPublishSyncCommand extends Command
{
    private const TIMEOUT_SEC = 60;

    private const FLUSH_EVERY = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $apiToken,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только посчитать, не публиковать')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Окно в часах (0 = вся база)', 0)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $hours = (int) $input->getOption('hours');

        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->apiToken) === '') {
            $io->error('Не заданы PROD_API_URL / AGENT_API_TOKEN в .env.local — команда только с Mac.');
            return Command::FAILURE;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                rtrim($this->prodApiUrl, '/') . '/api/v1/brands/published?hours=' . $hours,
                [
                    'headers' => ['X-Agent-Token' => $this->apiToken],
                    'timeout' => self::TIMEOUT_SEC,
                ],
            );
        } catch (\Throwable $e) {
            $io->error('Запрос к проду не прошёл: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($response->getStatusCode() !== 200) {
            $io->error(sprintf('Прод ответил HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 500)));
            return Command::FAILURE;
        }

        $items = $response->toArray(false)['items'] ?? [];
        if (!is_array($items) || $items === []) {
            $io->success('Прод ничего не вернул — синкать нечего.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Синк публикации прод→Mac: %d записей%s', count($items), $dryRun ? ' (dry-run)' : ''));

        $synced = 0;
        $already = 0;
        $missing = 0;
        $wouldSync = 0;
        $conflictSlugs = [];
        $processed = 0;

        $io->progressStart(count($items));
        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            if ($slug === null) {
                $io->progressAdvance();
                continue;
            }

            $brand = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
            if ($brand === null) {
                $missing++;
                if ($output->isVerbose()) {
                    $io->text("  нет на Mac: {$slug}");
                }
                $io->progressAdvance();
                continue;
            }

            if ($brand->getStatus() === Statuses::Active && $brand->getPublishedAt() !== null) {
                $already++;
                $io->progressAdvance();
                continue;
            }

            $prodPublishedAtRaw = $item['published_at'] ?? null;
            $prodPublishedAt = $prodPublishedAtRaw ? \DateTime::createFromFormat('Y-m-d H:i:s', (string) $prodPublishedAtRaw) : null;
            $prodPublishedAt = $prodPublishedAt instanceof \DateTime ? $prodPublishedAt : null;

            if ($dryRun) {
                $wouldSync++;
                $io->text("  → синканем: {$slug}");
            } elseif ($brand->getStatus() === Statuses::Active) {
                // Сюда попадает только active БЕЗ published_at (иначе бы ушёл в $already выше):
                // publish() для active — no-op и дату НЕ проставит, поэтому бэкфиллим напрямую.
                // Иначе такой бренд «синкался» бы каждый прогон, оставаясь без даты (не идемпотентно).
                $brand->setPublishedAt($prodPublishedAt ?? new \DateTime('now', new \DateTimeZone('Europe/Moscow')));
                $synced++;
            } else {
                try {
                    $brand->publish($prodPublishedAt);
                    $synced++;
                } catch (\DomainException) {
                    // Deleted/system на Mac, а на проде опубликован — реальный рассинхрон,
                    // публиковать вслепую нельзя (нужно ручное решение).
                    $conflictSlugs[] = $slug;
                }
            }

            $processed++;
            if (!$dryRun && $processed % self::FLUSH_EVERY === 0) {
                $this->em->flush();
                $this->em->clear();
                gc_collect_cycles();
            }

            $io->progressAdvance();
        }
        $io->progressFinish();

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(
            ['Результат', 'Кол-во'],
            [
                ['Синхронизировано', $dryRun ? $wouldSync : $synced],
                ['Уже актуальны', $already],
                ['Нет на Mac', $missing],
                ['Конфликт (deleted/system)', count($conflictSlugs)],
            ],
        );

        if ($conflictSlugs !== []) {
            $io->warning('Опубликованы на проде, но на Mac в deleted/system статусе — разобраться вручную:');
            $io->listing($conflictSlugs);
        }

        return Command::SUCCESS;
    }
}
