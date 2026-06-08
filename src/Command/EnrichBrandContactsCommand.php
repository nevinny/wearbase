<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandLink;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandStore;
use App\Entity\BrandSourceDocument;
use App\Repository\BrandRepository;
use App\Service\ContactVerifier;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Обогащает бренды контактами из ЛОКАЛЬНОГО скрейп-корпуса (27b). Без корпуса — пропуск до fetch.
 *
 * Безопасен для фонового запуска:
 *  - Каждый бренд помечается после обработки (contactEnrichedAt + contactStatus)
 *  - Статус 'not_found' является терминальным — бренд больше не обрабатывается
 *  - Статус 'error' допускает повторную попытку (до 3 раз по умолчанию)
 *  - Проверяет найденные URL через HTTP перед сохранением
 *  - Не перезаписывает уже существующие данные (email, phone, links по URL/типу)
 *  - После DB-ошибки автоматически пересоздаёт EntityManager через ManagerRegistry
 *
 * Примеры запуска:
 *
 *   # Тест без сохранения
 *   php bin/console app:brand:enrich-contacts 5 --dry-run
 *
 *   # Реальный запуск 50 брендов
 *   php bin/console app:brand:enrich-contacts
 *
 *   # Фоновый запуск 500 брендов с логом
 *   nohup php bin/console app:brand:enrich-contacts 500 --quiet >> var/log/enrich.log 2>&1 &
 *
 *   # Один конкретный бренд
 *   php bin/console app:brand:enrich-contacts --id=42
 *
 *   # Переобработать всё (включая уже enriched/partial)
 *   php bin/console app:brand:enrich-contacts 100 --force
 */
#[AsCommand(
    name: 'app:brand:enrich-contacts',
    description: 'Обогащение брендов контактами из локального скрейп-корпуса (27b)',
)]
class EnrichBrandContactsCommand extends Command
{
    private const MAX_ERROR_RETRIES    = 3;
    private const SLEEP_BETWEEN_MS     = 1200; // мс между запросами к API

    // Счётчики
    private int $enriched  = 0;
    private int $partial   = 0;
    private int $notFound  = 0;
    private int $failed    = 0;

    /**
     * EM не readonly — после DB-ошибки его нужно пересоздать через ManagerRegistry.
     */
    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly LlmService      $llmService,
        private readonly ContactVerifier $contactVerifier,
    ) {
        parent::__construct();
        $this->em = $this->managerRegistry->getManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit',       InputArgument::OPTIONAL, 'Максимум брендов за один запуск', 50)
            ->addOption('dry-run',   null, InputOption::VALUE_NONE,     'Не сохранять в БД')
            ->addOption('id',        null, InputOption::VALUE_REQUIRED, 'Обработать один бренд по ID')
            ->addOption('force',     null, InputOption::VALUE_NONE,     'Переобработать все, включая уже enriched/partial')
            ->addOption('no-verify', null, InputOption::VALUE_NONE,     'Не проверять URL через HTTP (быстрее, но опаснее)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $limit    = (int) $input->getArgument('limit');
        $dryRun   = $input->getOption('dry-run');
        $brandId  = $input->getOption('id');
        $force    = $input->getOption('force');
        $noVerify = $input->getOption('no-verify');

        $io->title('Обогащение брендов контактами (локальный корпус)');

        if ($dryRun)   { $io->note('Режим dry-run — изменения не будут сохранены'); }
        if ($noVerify) { $io->note('URL-проверка отключена'); }

        // Один бренд по --id
        if ($brandId !== null) {
            $brand = $this->em->find(Brand::class, (int) $brandId);
            if (!$brand) {
                $io->error("Бренд с ID {$brandId} не найден.");
                return Command::FAILURE;
            }
            $this->processBrand($brand, $io, $dryRun, $noVerify);
            $this->printResults($io);
            return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        // Пакетная обработка
        /** @var \App\Repository\BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);

        // Собираем только ID — грузим каждый бренд свежим запросом,
        // после чего вызываем em->clear() чтобы EM не накапливал detached-сущности
        // (классическая проблема: BrandStore.brand → отсоединённый Brand → EM паникует)
        $brandIds = array_map(
            fn(Brand $b) => $b->getId(),
            $repo->findForContactEnrichment(limit: $limit, force: $force),
        );

        if (count($brandIds) === 0) {
            $io->success('Нет брендов для обработки. Все enriched или not_found.');
            $io->text('Используй --force для переобработки.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Брендов к обработке: %d', count($brandIds)));
        $this->em->clear(); // освобождаем память после загрузки списка ID
        $io->progressStart(count($brandIds));

        foreach ($brandIds as $id) {
            // Загружаем бренд свежим запросом каждую итерацию
            $brand = $this->em->find(Brand::class, $id);
            if (!$brand) {
                $io->progressAdvance();
                continue;
            }

            $this->processBrand($brand, $io, $dryRun, $noVerify);
            $io->progressAdvance();
            usleep(self::SLEEP_BETWEEN_MS * 1000);
        }

        $io->progressFinish();
        $this->printResults($io);

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // =========================================================================
    // Обработка одного бренда
    // =========================================================================

    private function processBrand(Brand $brand, SymfonyStyle $io, bool $dryRun, bool $noVerify): void
    {
        $name = $brand->getTitle() ?? "ID:{$brand->getId()}";
        $io->text(sprintf('  → %s (attempts: %d)', $name, $brand->getContactAttempts()));

        try {
            // 1. Источник контактов: локальный скрейп-текст (если есть) → без платного Perplexity.
            $docs = $this->em->getRepository(BrandSourceDocument::class)->findByBrand($brand);
            $scrapedText = trim(implode("\n\n", array_filter(array_map(
                static fn(BrandSourceDocument $d) => $d->getCleanText(),
                $docs,
            ))));

            if ($scrapedText === '') {
                // Корпуса ещё нет — ждём discover/fetch: статус не трогаем, бренд останется
                // в выборке и обогатится, когда конвейер принесёт текст. Платный
                // Perplexity-fallback удалён (бэклог п.10, 2026-06-04).
                $io->text('    нет корпуса — пропуск до fetch');
                return;
            }

            $data = $this->llmService->extractBrandContactsFromText(
                brandName:   $name,
                scrapedText: $scrapedText,
                city:        $brand->getCity(),
            );
            $io->text(sprintf('    источник: локальный скрейп (%d стр.)', count($docs)));

            $io->text(sprintf('    confidence: %s | %s', $data['confidence'], $data['notes'] ?? ''));

            $status = match ($data['confidence']) {
                'high', 'medium' => 'enriched',
                'low'            => 'partial',
                'not_found'      => 'not_found',
                default          => 'partial',
            };

            // 2. Применяем данные
            if ($status !== 'not_found') {
                if ($dryRun) {
                    $this->previewContacts($data, $brand, $io);
                } elseif ($this->applyContacts($brand, $data, $noVerify, $io)) {
                    // Контакты/магазины/ссылки реально записаны → ре-доставка на прод.
                    $this->em->getRepository(BrandRagPipeline::class)->markContentChanged($brand);
                }
            } else {
                $io->text('    ⊘ бренд не найден в интернете');
            }

            // 3. Всегда помечаем как обработанный
            $brand->setContactStatus($status);
            $brand->setContactEnrichedAt(new \DateTime());
            $brand->setContactAttempts($brand->getContactAttempts() + 1);

            if (!$dryRun) {
                $this->em->flush();
                // clear() вместо detach() — полностью сбрасываем Unit of Work,
                // чтобы BrandStore/BrandLink не тащили за собой ссылки на старые бренды
                $this->em->clear();
            }

            match ($status) {
                'enriched'  => $this->enriched++,
                'partial'   => $this->partial++,
                'not_found' => $this->notFound++,
                default     => $this->partial++,
            };

        } catch (\Exception $e) {
            $io->warning(sprintf('    Ошибка «%s»: %s', $name, $e->getMessage()));

            // Если EM сломан DB-ошибкой — пересоздаём, иначе весь батч упадёт
            if (!$this->em->isOpen()) {
                $this->em = $this->managerRegistry->resetManager();
            } else {
                // Откатываем незафиксированные изменения текущего бренда
                $this->em->clear();
            }

            // Записываем статус 'error' свежим чистым EM
            if (!$dryRun) {
                try {
                    $freshBrand = $this->em->find(Brand::class, $brand->getId());
                    if ($freshBrand) {
                        $freshBrand->setContactStatus('error');
                        $freshBrand->setContactEnrichedAt(new \DateTime());
                        $freshBrand->setContactAttempts($freshBrand->getContactAttempts() + 1);
                        $this->em->flush();
                        $this->em->clear();
                    }
                } catch (\Exception $inner) {
                    $io->warning(sprintf('    Не удалось сохранить статус error: %s', $inner->getMessage()));
                }
            }

            $this->failed++;
        }
    }

    // =========================================================================
    // Применение данных к бренду
    // =========================================================================

    /** @return bool true = что-то реально записано (для пометки ре-доставки на прод) */
    private function applyContacts(Brand $brand, array $data, bool $noVerify, SymfonyStyle $io): bool
    {
        $changed = false;
        // ── Email ──────────────────────────────────────────────────────────────
        if ($data['email'] !== null
            && $this->contactVerifier->validateEmail($data['email'])
            && $brand->getEmail() === null   // не перезаписываем существующий
        ) {
            $brand->setEmail($data['email']);
            $changed = true;
            $io->text("    ✓ email: {$data['email']}");
        } elseif ($brand->getEmail() !== null) {
            $io->text("    ⏭ email: уже есть ({$brand->getEmail()})");
        }

        // ── Phone ──────────────────────────────────────────────────────────────
        if ($data['phone'] !== null
            && $this->contactVerifier->validatePhone($data['phone'])
            && $brand->getPhone() === null
        ) {
            $brand->setPhone(mb_substr($data['phone'], 0, 20));
            $changed = true;
            $io->text("    ✓ phone: {$data['phone']}");
        } elseif ($brand->getPhone() !== null) {
            $io->text("    ⏭ phone: уже есть ({$brand->getPhone()})");
        }

        // ── Links (website, instagram, vk, telegram, youtube) ─────────────────
        foreach (['website', 'instagram', 'vk', 'telegram', 'youtube'] as $type) {
            $url = $data[$type] ?? null;
            if ($url === null) {
                continue;
            }

            $normalizedUrl = $this->contactVerifier->normalizeUrl($url);
            if ($normalizedUrl === null) {
                continue;
            }

            // Проверяем дубли: по типу И по URL (старые ссылки без link_type)
            if ($this->brandHasLink($brand, $type, $normalizedUrl)) {
                $io->text("    ⏭ {$type}: уже есть");
                continue;
            }

            // HTTP-верификация только для website (соцсети часто 403)
            if ($type === 'website' && !$noVerify) {
                if (!$this->contactVerifier->verifyUrl($normalizedUrl)) {
                    $io->text("    ✗ website: {$normalizedUrl} (HTTP-проверка провалена)");
                    continue;
                }
            }

            $link = new BrandLink();
            $link->setLinkUrl($normalizedUrl);
            $link->setLinkType($type);
            $link->setTitle($this->linkTitle($type, $normalizedUrl));
            // DefaultFields требует slug NOT NULL — генерируем из URL
            $link->setSlug(substr(md5($type . $normalizedUrl), 0, 24));
            $brand->addLink($link);
            $changed = true;
            $io->text("    ✓ {$type}: {$normalizedUrl}");
        }

        // ── Магазины ───────────────────────────────────────────────────────────
        if (!empty($data['stores'])) {
            foreach ($data['stores'] as $storeData) {
                $address = trim($storeData['address'] ?? '');
                if ($address === '') {
                    continue;
                }

                if ($this->brandHasStore($brand, $address)) {
                    continue;
                }

                $store = new BrandStore();
                $store->setAddress(mb_substr($address, 0, 500));
                $store->setCity($storeData['city'] ?? null);

                $storePhone = $storeData['phone'] ?? null;
                if ($storePhone !== null && $this->contactVerifier->validatePhone($storePhone)) {
                    $store->setPhone(mb_substr($storePhone, 0, 30));
                }

                $brand->addStore($store);
                $changed = true;
                $io->text("    ✓ store: {$address}");
            }
        }

        return $changed;
    }

    private function previewContacts(array $data, Brand $brand, SymfonyStyle $io): void
    {
        $io->text('    [dry-run] Было бы сохранено:');

        foreach (['email', 'phone'] as $field) {
            if ($data[$field] !== null) {
                $existing = $field === 'email' ? $brand->getEmail() : $brand->getPhone();
                $flag = $existing ? '⏭ уже есть' : '✓';
                $io->text("      {$flag} {$field}: {$data[$field]}");
            }
        }

        foreach (['website', 'instagram', 'vk', 'telegram', 'youtube'] as $type) {
            if (($data[$type] ?? null) !== null) {
                $norm = $this->contactVerifier->normalizeUrl($data[$type]);
                if ($norm) {
                    $flag = $this->brandHasLink($brand, $type, $norm) ? '⏭ уже есть' : '✓';
                    $io->text("      {$flag} {$type}: {$norm}");
                }
            }
        }

        foreach ($data['stores'] as $s) {
            $address = $s['address'] ?? '';
            $flag = $this->brandHasStore($brand, $address) ? '⏭ уже есть' : '✓';
            $io->text("      {$flag} store: {$address}" . ($s['city'] ? " ({$s['city']})" : ''));
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Человекочитаемый заголовок ссылки по типу.
     * Для website — домен из URL (например "anteaterclothing.com").
     */
    private function linkTitle(string $type, string $url): string
    {
        return match ($type) {
            'vk'        => 'VK',
            'instagram' => 'Instagram',
            'telegram'  => 'Telegram',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
            'website'   => parse_url($url, PHP_URL_HOST) ?? $url,
            default     => $url,
        };
    }

    /**
     * Проверяет дубль ссылки:
     *  - по linkType (новые ссылки)
     *  - по совпадению URL (старые ссылки без linkType)
     */
    private function brandHasLink(Brand $brand, string $type, string $normalizedUrl): bool
    {
        foreach ($brand->getLinks() as $link) {
            if ($link->getLinkType() === $type) {
                return true;
            }
            // Старая ссылка без типа — совпадение по URL
            if ($link->getLinkUrl() !== null
                && rtrim($link->getLinkUrl(), '/') === rtrim($normalizedUrl, '/')
            ) {
                return true;
            }
        }
        return false;
    }

    private function brandHasStore(Brand $brand, string $address): bool
    {
        $norm = fn(string $s): string => mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
        foreach ($brand->getStores() as $store) {
            if ($norm($store->getAddress()) === $norm($address)) {
                return true;
            }
        }
        return false;
    }

    private function printResults(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->table(
            ['Результат', 'Количество'],
            [
                ['Обогащено (high/medium confidence)', $this->enriched],
                ['Частично (low confidence)',          $this->partial],
                ['Не найдено в интернете',             $this->notFound],
                ['Ошибок запроса (retry возможен)',    $this->failed],
            ],
        );

        $total = $this->enriched + $this->partial + $this->notFound + $this->failed;
        if ($total > 0) {
            $rate = round(($this->enriched + $this->partial) / $total * 100);
            $io->text("Успешность: {$rate}% ({$total} обработано)");
        }
    }
}
