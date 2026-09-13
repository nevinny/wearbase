<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;
use App\Service\Wardrobe\WardrobeAiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

#[AsCommand(
    name: 'app:wardrobe:prepare-existing-items',
    description: 'Дополняет пустые AI-атрибуты существующих вещей по их фото',
)]
final class PrepareExistingItemsCommand extends Command
{
    private $lockHandle = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WardrobeItemRepository $items,
        private readonly WardrobeAiService $ai,
        private readonly StorageInterface $storage,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email владельца гардероба')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум вещей за запуск', 15)
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Начать после ID вещи', 0)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать найденные вещи');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->acquireLock()) {
            $io->note('Другой запуск уже обрабатывает вещи.');
            return Command::SUCCESS;
        }

        $email = trim((string) $input->getOption('user'));
        if ($email === '') {
            $io->error('Опция --user=EMAIL обязательна');
            return Command::FAILURE;
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error('Пользователь не найден: '.$email);
            return Command::FAILURE;
        }

        $limit = max(1, min(100, (int) $input->getOption('limit')));
        $after = max(0, (int) $input->getOption('after'));
        $items = $this->items->findNeedingPreparation($user, $after, $limit);
        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Найдено %d вещей%s.', count($items), $hasMore ? ' (есть продолжение)' : ''));
            foreach ($items as $item) {
                $io->writeln(sprintf('%s %s', $item->getDisplayNumber(), $item->getName() ?: 'Без названия'));
            }
            return Command::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;
        foreach ($items as $item) {
            $path = $this->storage->resolvePath($item, 'photoFile');
            if (!is_string($path) || !is_file($path)) {
                $skipped++;
                $io->writeln(sprintf('<comment>%s: фото не найдено</comment>', $item->getDisplayNumber()));
                continue;
            }

            $result = $this->ai->suggestFromPhoto($path, $user);
            if (!($result['ok'] ?? false)) {
                $skipped++;
                $io->writeln(sprintf('<comment>%s: %s</comment>', $item->getDisplayNumber(), $result['error'] ?? 'AI недоступен'));
                continue;
            }
            $fields = $result['fields'] ?? [];
            $changed = false;
            if (!$this->hasValue($item->getCategory()) && $this->hasValue($fields['category'] ?? null)) {
                $item->setCategory((string) $fields['category']);
                $changed = true;
            }
            if (!$this->hasValue($item->getColorName()) && $this->hasValue($fields['colorName'] ?? null)) {
                $item->setColorName((string) $fields['colorName']);
                $changed = true;
            }
            if (!$this->hasValue($item->getMaterialText()) && $this->hasValue($fields['materialText'] ?? null)) {
                $item->setMaterialText((string) $fields['materialText']);
                $changed = true;
            }
            if (!$this->hasValue($item->getSeason()) && in_array($fields['season'] ?? null, ['all', 'spring', 'summer', 'autumn', 'winter'], true)) {
                $item->setSeason($fields['season']);
                $changed = true;
            }
            if ($changed) {
                $this->em->flush();
                $updated++;
                $io->writeln(sprintf('Обновлена %s — %s', $item->getDisplayNumber(), $item->getName() ?: 'Без названия'));
            } else {
                $skipped++;
            }
        }

        $io->success(sprintf('Обновлено: %d, пропущено: %d%s', $updated, $skipped, $hasMore ? '. Продолжение: --after='.$items[array_key_last($items)]->getId() : ''));
        return Command::SUCCESS;
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function acquireLock(): bool
    {
        $path = $this->projectDir.'/var/wardrobe_prepare_items.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $handle;
        return true;
    }
}
