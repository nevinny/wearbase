<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\Wardrobe\WardrobeManager;
use App\Service\Wardrobe\WardrobeRemotePhotoFetcher;
use App\Service\Wardrobe\WildberriesAdapter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:wardrobe:fetch-wb-photos',
    description: 'Сохранить обложки Wildberries для вещей, у которых есть ссылка, но нет фото',
)]
final class FetchWardrobeWildberriesPhotosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WildberriesAdapter $wildberries,
        private readonly WardrobeRemotePhotoFetcher $photoFetcher,
        private readonly WardrobeManager $wardrobeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email владельца гардероба')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум вещей за запуск', 50)
            ->addOption('pause-ms', null, InputOption::VALUE_REQUIRED, 'Пауза между карточками WB, мс', 250)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать количество подходящих вещей');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getOption('user'));
        if ($email === '') {
            $io->error('Опция --user=EMAIL обязательна');
            return Command::FAILURE;
        }
        $limit = max(1, min(500, (int) $input->getOption('limit')));
        $pauseMs = max(0, min(10_000, (int) $input->getOption('pause-ms')));

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error("Пользователь не найден: {$email}");
            return Command::FAILURE;
        }

        /** @var WardrobeItem[] $items */
        $items = $this->entityManager->getRepository(WardrobeItem::class)
            ->createQueryBuilder('item')
            ->andWhere('item.user = :user')
            ->andWhere('item.photo IS NULL')
            ->andWhere('item.productUrl LIKE :wildberries')
            ->andWhere('item.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('wildberries', '%wildberries.ru/%')
            ->orderBy('item.itemNo', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Найдено %d вещей без фото', count($items)));
            return Command::SUCCESS;
        }

        $saved = 0;
        $failed = 0;
        foreach ($items as $index => $item) {
            $data = $this->wildberries->fetch((string) $item->getProductUrl());
            if (
                $data === null
                || $data['imageUrl'] === null
                || !$this->photoFetcher->attachWildberriesPhoto($item, $data['imageUrl'])
            ) {
                $failed++;
                $io->writeln(sprintf('<comment>Пропуск %s: фото WB недоступно</comment>', $item->getDisplayNumber()));
                $this->pause($index, count($items), $pauseMs);
                continue;
            }

            $this->wardrobeManager->refreshCompletionStatus($item);
            $this->entityManager->flush();
            $saved++;
            $io->writeln(sprintf('Сохранено фото %s — %s', $item->getDisplayNumber(), $item->getName()));
            $this->pause($index, count($items), $pauseMs);
        }

        $io->success(sprintf('Фото сохранено: %d, не удалось: %d', $saved, $failed));

        return Command::SUCCESS;
    }

    private function pause(int $index, int $total, int $pauseMs): void
    {
        if ($pauseMs > 0 && $index < $total - 1) {
            usleep($pauseMs * 1000);
        }
    }
}
