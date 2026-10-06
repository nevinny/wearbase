<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandInvite;
use App\Entity\BrandUser;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\ProductIntentClick;
use App\Entity\SellerLegalEntity;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Склейка дубля бренда в выжившую карточку. Переносит на survivor владельческие данные
 * (товары, команду, заказы, подписки, юрлица, приглашения, заявки, интент-клики), помечает
 * дубль `merged_into_id` и мягко удаляет (Brand::softDelete). После этого
 * BrandsController::show() отдаёт 301 с URL дубля на survivor.
 *
 * НЕ переносятся SEO/RAG/аналитические артефакты (gsc_*, brand_keyword, brand_rag_pipeline,
 * brand_source_*, brand_faq, brand_related, outreach, social_post, переводы, картинки и т.д.) —
 * они описывают URL и историю дубля и остаются на нём.
 *
 * Конфликты уникальности (товар с тем же slug у survivor) — строка дубля остаётся на дубле,
 * попадает в отчёт «пропущено». Участник команды, уже состоящий в survivor, не переносится:
 * его дублирующая связь удаляется (системная операция консоли). Повторный запуск — no-op.
 *
 *   php bin/console app:brand:merge <duplicateId> <survivorId> [--dry-run]
 */
#[AsCommand(
    name: 'app:brand:merge',
    description: 'Склеить дубль бренда с выжившей карточкой (перенос данных владельца + 301)',
)]
class BrandMergeCommand extends Command
{
    /** Сущности, у которых бренд просто переназначается (без особых конфликтов). */
    private const SIMPLE_ENTITIES = [
        'order'               => Order::class,
        'subscription'        => Subscription::class,
        'seller_legal_entity' => SellerLegalEntity::class,
        'brand_invite'        => BrandInvite::class,
        'brand_claim'         => BrandClaim::class,
        'product_intent_click' => ProductIntentClick::class,
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('duplicateId', InputArgument::REQUIRED, 'ID бренда-дубля')
            ->addArgument('survivorId', InputArgument::REQUIRED, 'ID выжившего бренда')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только посчитать, ничего не менять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dupId  = (int) $input->getArgument('duplicateId');
        $survId = (int) $input->getArgument('survivorId');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dupId === $survId) {
            $io->error('duplicateId и survivorId должны различаться');
            return Command::INVALID;
        }

        $duplicate = $this->em->find(Brand::class, $dupId);
        $survivor  = $this->em->find(Brand::class, $survId);
        if ($duplicate === null || $survivor === null) {
            $io->error(sprintf('Бренд #%d не найден', $duplicate === null ? $dupId : $survId));
            return Command::FAILURE;
        }

        if ($duplicate->getMergedInto() !== null) {
            if ($duplicate->getMergedInto()->getId() === $survId) {
                $io->note(sprintf('Бренд #%d уже склеен с #%d — делать нечего', $dupId, $survId));
                return Command::SUCCESS;
            }
            $io->error(sprintf('Бренд #%d уже склеен с другим брендом #%d', $dupId, $duplicate->getMergedInto()->getId()));
            return Command::FAILURE;
        }

        if ($survivor->getMergedInto() !== null) {
            $io->error(sprintf('Выживший бренд #%d сам склеен с #%d — цепочки запрещены', $survId, $survivor->getMergedInto()->getId()));
            return Command::FAILURE;
        }

        /** @var array<string, array{moved:int, dropped:int, skipped:int}> $report */
        $report = [];
        $work   = function () use ($duplicate, $survivor, $dryRun, &$report): void {
            $report['product']    = $this->moveProducts($duplicate, $survivor, $dryRun);
            $report['brand_user'] = $this->moveMembers($duplicate, $survivor, $dryRun);
            foreach (self::SIMPLE_ENTITIES as $table => $class) {
                $rows = $this->em->getRepository($class)->findBy(['brand' => $duplicate]);
                foreach ($rows as $row) {
                    if (!$dryRun) {
                        $row->setBrand($survivor);
                    }
                }
                $report[$table] = ['moved' => count($rows), 'dropped' => 0, 'skipped' => 0];
            }

            if (!$dryRun) {
                $duplicate->setMergedInto($survivor);
                $duplicate->softDelete();
                $this->em->flush();
            }
        };

        if ($dryRun) {
            $work();
        } else {
            $this->em->wrapInTransaction($work);
        }

        $io->table(
            ['Таблица', $dryRun ? 'Перенесётся' : 'Перенесено', 'Удалено (дубль связи)', 'Пропущено (конфликт)'],
            array_map(
                static fn(string $t, array $r) => [$t, $r['moved'], $r['dropped'], $r['skipped']],
                array_keys($report),
                $report,
            ),
        );

        $io->success($dryRun
            ? sprintf('dry-run: бренд #%d → #%d, ничего не изменено', $dupId, $survId)
            : sprintf('Бренд #%d (%s) склеен в #%d (%s) и мягко удалён', $dupId, $duplicate->getSlug(), $survId, $survivor->getSlug()));

        return Command::SUCCESS;
    }

    /** @return array{moved:int, dropped:int, skipped:int} */
    private function moveProducts(Brand $duplicate, Brand $survivor, bool $dryRun): array
    {
        $repo       = $this->em->getRepository(Product::class);
        $survSlugs  = array_map(static fn(Product $p) => $p->getSlug(), $repo->findBy(['brand' => $survivor]));
        $moved = $skipped = 0;

        foreach ($repo->findBy(['brand' => $duplicate]) as $product) {
            // UNIQUE (brand_id, slug): при конфликте оставляем товар на дубле.
            if (in_array($product->getSlug(), $survSlugs, true)) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $product->setBrand($survivor);
            }
            $survSlugs[] = $product->getSlug();
            $moved++;
        }

        return ['moved' => $moved, 'dropped' => 0, 'skipped' => $skipped];
    }

    /** @return array{moved:int, dropped:int, skipped:int} */
    private function moveMembers(Brand $duplicate, Brand $survivor, bool $dryRun): array
    {
        $repo = $this->em->getRepository(BrandUser::class);
        $existing = [];
        foreach ($repo->findBy(['brand' => $survivor]) as $member) {
            $existing[$member->getUser()?->getId()] = true;
        }

        $moved = $dropped = 0;
        foreach ($repo->findBy(['brand' => $duplicate]) as $member) {
            if (isset($existing[$member->getUser()?->getId()])) {
                if (!$dryRun) {
                    $this->em->remove($member);
                }
                $dropped++;
                continue;
            }
            if (!$dryRun) {
                $member->setBrand($survivor);
            }
            $moved++;
        }

        return ['moved' => $moved, 'dropped' => $dropped, 'skipped' => 0];
    }
}
