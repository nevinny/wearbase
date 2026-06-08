<?php

namespace App\Command;

use App\Entity\Brand;
use App\Repository\BrandRepository;
use App\Service\BrandRagService;
use App\Service\ContactVerifier;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Актуализация контактных данных брендов из RAG-корпуса.
 *
 *   ./bin/console app:contacts:refresh --limit=50 --ttl=180
 *   ./bin/console app:contacts:refresh --limit=10 --full --force --dry-run
 *   ./bin/console app:contacts:refresh --daemon --interval=3600 --no-debug
 */
#[AsCommand(
    name: 'app:contacts:refresh',
    description: 'Актуализация контактов брендов из RAG-корпуса',
)]
class BrandRefreshContactsCommand extends Command
{
    private EntityManagerInterface $em;
    private int $processed = 0;
    private int $updated   = 0;
    private int $skipped   = 0;
    private int $errors    = 0;
    private array $changes = [];

    public function __construct(
        private readonly ManagerRegistry   $managerRegistry,
        private readonly BrandRagService   $rag,
        private readonly LlmService        $llm,
        private readonly ContactVerifier   $verifier,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit',     null, InputOption::VALUE_REQUIRED, 'Максимум брендов за запуск', 50)
            ->addOption('ttl',       null, InputOption::VALUE_REQUIRED, 'Дней после обогащения для ревалидации', '180')
            ->addOption('full',      null, InputOption::VALUE_NONE,     'Извлекать все контакты (по умолчанию только email)')
            ->addOption('force',     null, InputOption::VALUE_NONE,     'Игнорировать TTL, обработать все')
            ->addOption('dry-run',   null, InputOption::VALUE_NONE,     'Только показать, ничего не писать')
            ->addOption('daemon',    null, InputOption::VALUE_NONE,     'Цикличный режим')
            ->addOption('interval',  null, InputOption::VALUE_REQUIRED, 'Пауза между циклами в секундах', '3600')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit    = (int) $input->getOption('limit');
        $ttl      = (int) $input->getOption('ttl');
        $full     = (bool) $input->getOption('full');
        $force    = (bool) $input->getOption('force');
        $dryRun   = (bool) $input->getOption('dry-run');
        $daemon   = (bool) $input->getOption('daemon');
        $interval = (int) $input->getOption('interval');

        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        do {
            $this->processCycle($io, $limit, $ttl, $full, $force, $dryRun);

            if (!$daemon) {
                break;
            }

            if ($this->skipped + $this->processed === 0) {
                $io->success('Нет брендов для обработки, ждём следующий цикл.');
            }

            $io->text(sprintf('Пауза %d сек…', $interval));
            sleep($interval);
            $this->em->clear();
        } while (true);

        return $this->errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processCycle(SymfonyStyle $io, int $limit, int $ttl, bool $full, bool $force, bool $dryRun): void
    {
        /** @var BrandRepository $repo */
        $repo  = $this->em->getRepository(Brand::class);
        $brands = $repo->findForContactRefresh($limit, $ttl, $force);

        if ($brands === []) {
            $io->success('Нет брендов для актуализации контактов.');
            return;
        }

        $io->section(sprintf('Актуализация контактов: %d брендов (TTL=%dд, full=%s)', count($brands), $ttl, $full ? 'да' : 'нет'));
        $io->progressStart(count($brands));

        foreach ($brands as $brand) {
            try {
                $this->refreshBrand($brand, $full, $dryRun);
            } catch (\Throwable $e) {
                $this->errors++;
                $io->warning(sprintf('  Ошибка «%s»: %s', $brand->getTitle() ?? 'ID:'.$brand->getId(), $e->getMessage()));
                if (!$this->em->isOpen()) {
                    $this->em = $this->managerRegistry->resetManager();
                }
            }
            $io->progressAdvance();
            gc_collect_cycles();
        }

        $io->progressFinish();

        $io->table(
            ['Результат', 'Кол-во'],
            [
                ['Обработано',  $this->processed],
                ['Обновлено',   $this->updated],
                ['Пропущено',   $this->skipped],
                ['Ошибок',      $this->errors],
            ]
        );

        if ($this->changes !== [] && $io->isVerbose()) {
            $io->section('Изменения:');
            foreach ($this->changes as $c) {
                $io->text($c);
            }
        }
    }

    private function refreshBrand(Brand $brand, bool $full, bool $dryRun): void
    {
        $name = $brand->getTitle() ?? 'ID:'.$brand->getId();

        $result = $this->rag->retrieve($brand);
        if ($result['context'] === null) {
            $this->skipped++;
            return;
        }

        $extracted = $this->llm->extractContactsFromContext($name, $result['context']);

        $dirty = false;

        // --- Email (всегда) ---
        $email = $this->extractEmailFromText($result['context']) ?? $extracted['email'];
        if ($email !== null && $this->verifier->validateEmail($email)) {
            if ($brand->getEmail() === null) {
                if (!$dryRun) {
                    $brand->setEmail($email);
                }
                $this->changes[] = "{$name}: email ← {$email}";
                $dirty = true;
            } elseif ($brand->getEmail() !== $email) {
                $this->changes[] = "{$name}: email ≠ корпус (БД:{$brand->getEmail()}, корпус:{$email}) — не заменён";
            }
        }

        if (!$full) {
            $this->finishBrand($brand, $dirty, $dryRun);
            return;
        }

        // --- Phone ---
        if ($extracted['phone'] !== null && $this->verifier->validatePhone($extracted['phone'])) {
            if ($brand->getPhone() === null) {
                if (!$dryRun) {
                    $brand->setPhone($extracted['phone']);
                }
                $this->changes[] = "{$name}: phone ← {$extracted['phone']}";
                $dirty = true;
            } elseif ($brand->getPhone() !== $extracted['phone']) {
                $this->changes[] = "{$name}: phone ≠ корпус (БД:{$brand->getPhone()}, корпус:{$extracted['phone']})";
            }
        }

        // --- Address ---
        if ($extracted['address'] !== null) {
            if ($brand->getAddress() === null) {
                if (!$dryRun) {
                    $brand->setAddress($extracted['address']);
                }
                $this->changes[] = "{$name}: address ← {$extracted['address']}";
                $dirty = true;
            } elseif ($brand->getAddress() !== $extracted['address']) {
                $this->changes[] = "{$name}: address ≠ корпус (БД:{$brand->getAddress()}, корпус:{$extracted['address']})";
            }
        }

        // --- Social links (только добавить, не заменять) ---
        if ($extracted['social'] !== []) {
            $existingUrls = [];
            foreach ($brand->getLinks() as $link) {
                $u = $link->getLinkUrl();
                if ($u !== null) {
                    $existingUrls[] = rtrim($u, '/');
                }
            }
            foreach ($extracted['social'] as $socialUrl) {
                $normalized = $this->verifier->normalizeUrl($socialUrl);
                if ($normalized !== null && !in_array(rtrim($normalized, '/'), $existingUrls, true)) {
                    if (!$dryRun) {
                        $link = new \App\Entity\BrandLink();
                        $link->setBrand($brand)->setLinkUrl($normalized);
                        $this->em->persist($link);
                    }
                    $this->changes[] = "{$name}: link + {$normalized}";
                    $dirty = true;
                }
            }
        }

        $this->finishBrand($brand, $dirty, $dryRun);
    }

    private function finishBrand(Brand $brand, bool $dirty, bool $dryRun): void
    {
        $now = new \DateTime();

        if ($dirty && !$dryRun) {
            $brand->setContentVersion($brand->getContentVersion() + 1);
            // Контакты изменились → пометить для ре-доставки на прод (push-предикат).
            $this->em->getRepository(\App\Entity\BrandRagPipeline::class)->markContentChanged($brand);
        }

        $brand->setContactEnrichedAt($now);
        $status = $brand->getEmail() !== null ? 'enriched' : 'partial';
        $brand->setContactStatus($status);
        $brand->setContactAttempts(($brand->getContactAttempts() ?? 0) + 1);

        if (!$dryRun) {
            $this->em->flush();
        }
        $this->em->clear();

        if ($dirty) {
            $this->updated++;
        }
        $this->processed++;
    }

    /**
     * Быстрое извлечение email из текста регуляркой (без LLM).
     * Fallback: если regex не дал результата, возвращаем null.
     */
    private function extractEmailFromText(string $text): ?string
    {
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $m)) {
            $unique = array_unique($m[0]);
            // Игнорируем «@telodvigeniia»-подобные (локальные части без точки в домене)
            foreach ($unique as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    return $e;
                }
            }
        }

        return null;
    }
}
