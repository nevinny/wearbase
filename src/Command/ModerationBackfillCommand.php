<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Repository\BrandModerationRepository;
use App\Repository\BrandUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Подчистка очереди премодерации (BrandModeration, PR #69) для самрег-брендов, заведённых
 * ДО того, как RegisterController стал создавать строку `queued` при регистрации.
 *
 * Штатный путь — хук в RegisterController: он ставит новый бренд в очередь сразу при
 * self-register. Эта команда не заменяет хук, а разово (или по мере обнаружения потерянных
 * заявок) находит бренды с владельцем (BrandUser::ROLE_OWNER — признак self-register/claim,
 * у каталожных брендов из импортов такой строки нет), у которых `brand_moderation` пуста, и
 * дописывает недостающую строку задним числом.
 *
 * Идемпотентна: бренд с уже существующей строкой в brand_moderation пропускается.
 * Удалённые бренды (Statuses::Deleted) не обрабатываются.
 *
 *   php bin/console app:brand:moderation-backfill --dry-run   # показать, что будет создано
 *   php bin/console app:brand:moderation-backfill              # применить
 *   php bin/console app:brand:moderation-backfill --limit=500
 */
#[AsCommand(
    name: 'app:brand:moderation-backfill',
    description: 'Задним числом ставит self-register бренды без строки в brand_moderation в очередь премодерации',
)]
class ModerationBackfillCommand extends Command
{
    public function __construct(
        private readonly BrandUserRepository $brandUserRepo,
        private readonly BrandModerationRepository $moderationRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, ничего не писать')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько owner-брендов обработать за прогон', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = (int) $input->getOption('limit');

        /** @var BrandUser[] $owners */
        $owners = $this->brandUserRepo->findBy(['role' => BrandUser::ROLE_OWNER]);

        /** @var array<int,Brand> $brands */
        $brands = [];
        foreach ($owners as $owner) {
            $brand = $owner->getBrand();
            if ($brand !== null) {
                $brands[$brand->getId()] = $brand;
            }
        }
        ksort($brands);
        $brands = array_slice($brands, 0, $limit, true);

        $rows      = [];
        $created   = 0;
        $existing  = 0;
        $skipped   = 0;
        foreach ($brands as $brand) {
            $status = $brand->getStatus();

            if ($status === Statuses::Deleted) {
                $rows[] = [$brand->getId(), $brand->getTitle(), $status->value, 'пропущен (удалён)'];
                $skipped++;
                continue;
            }

            if ($this->moderationRepo->findOneByBrand($brand) !== null) {
                $rows[] = [$brand->getId(), $brand->getTitle(), $status->value, 'уже была'];
                $existing++;
                continue;
            }

            $rows[] = [$brand->getId(), $brand->getTitle(), $status->value, $dryRun ? '[dry-run] будет создана' : 'создана строка'];
            $created++;

            if (!$dryRun) {
                $moderation = new BrandModeration();
                $moderation->setBrand($brand);
                $moderation->setSource(BrandModeration::SOURCE_SELF_REGISTER);
                $this->em->persist($moderation);
            }
        }

        if (!$dryRun && $created > 0) {
            $this->em->flush();
        }

        $io->table(['ID', 'Бренд', 'Статус', 'Результат'], $rows);
        $io->success(sprintf(
            '%s: %d из %d owner-брендов (уже была: %d, пропущено: %d)',
            $dryRun ? 'Будет создано' : 'Создано',
            $created,
            count($brands),
            $existing,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
