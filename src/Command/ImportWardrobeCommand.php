<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Импорт вещей гардероба пользователя из JSON-файла (напр. выгрузка из Telegram-бота
 * или ручной перенос старых записей). Дедуп по (user, item_no) — уже существующие записи
 * (в т.ч. soft-deleted) пропускаются, не перезаписываются.
 *
 *   php bin/console app:wardrobe:import var/wardrobe-import.json --user=user@example.com --dry-run
 *   php bin/console app:wardrobe:import var/wardrobe-import.json --user=user@example.com
 */
#[AsCommand(name: 'app:wardrobe:import', description: 'Импорт вещей гардероба пользователя из JSON-файла')]
class ImportWardrobeCommand extends Command
{
    private const VALID_LOVE = [WardrobeItem::LOVE_YES, WardrobeItem::LOVE_NO, WardrobeItem::LOVE_UNKNOWN];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WardrobeManager $wardrobeManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Путь к JSON-файлу с массивом вещей')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email пользователя-владельца гардероба')
            ->addOption('create-user', null, InputOption::VALUE_NONE, 'Создать обычного пользователя, если email ещё не зарегистрирован')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что было бы создано/пропущено')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $file   = (string) $input->getArgument('file');
        $email  = $input->getOption('user');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$email) {
            $io->error('Опция --user=EMAIL обязательна');
            return Command::FAILURE;
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            if (!$input->getOption('create-user') || $dryRun) {
                $io->error("Пользователь не найден: {$email}. Для создания используйте --create-user без --dry-run");
                return Command::FAILURE;
            }

            $temporaryPassword = bin2hex(random_bytes(10));
            $user = (new User())
                ->setEmail((string) $email)
                ->setRoles(['ROLE_CUSTOMER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $temporaryPassword));
            $this->em->persist($user);
            $this->em->flush();

            $io->warning([
                "Создан новый пользователь: {$email}",
                "Временный пароль: {$temporaryPassword}",
                'Смените пароль после первого входа.',
            ]);
        }

        if (!is_file($file)) {
            $io->error("Файл не найден: {$file}");
            return Command::FAILURE;
        }

        $raw = file_get_contents($file);
        $rows = json_decode($raw ?: '', true);
        if (!is_array($rows) || json_last_error() !== JSON_ERROR_NONE) {
            $io->error('Невалидный JSON: ожидается массив объектов');
            return Command::FAILURE;
        }

        // Существующие item_no пользователя, включая soft-deleted — дедуп по (user, item_no)
        $existingNos = array_map(
            'intval',
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT item_no FROM wardrobe_item WHERE user_id = ?',
                [$user->getId()],
            ),
        );
        $existingNos = array_flip($existingNos);
        $nextItemNo = $existingNos === [] ? 1 : max(array_keys($existingNos)) + 1;

        $io->section(sprintf('Импорт вещей для %s: записей в файле %d%s', $email, count($rows), $dryRun ? ' (dry-run)' : ''));

        $tableRows = [];
        $created = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $skipped++;
                $tableRows[] = [$i, '—', '—', 'skip: не объект'];
                continue;
            }

            $itemNo = (int) ($row['item_no'] ?? 0);
            if ($itemNo <= 0) {
                while (isset($existingNos[$nextItemNo])) {
                    $nextItemNo++;
                }
                $itemNo = $nextItemNo++;
            }
            $category = trim((string) ($row['category'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            if ($category === '' || $name === '') {
                $skipped++;
                $tableRows[] = [$itemNo ?: $i, $category, $name, 'skip: category/name пусты'];
                continue;
            }

            $love = $row['love_at_first_sight'] ?? $row['loveAtFirstSight'] ?? null;
            if ($love !== null && !\in_array($love, self::VALID_LOVE, true)) {
                $skipped++;
                $tableRows[] = [$itemNo, $category, $name, "skip: недопустимое love_at_first_sight «{$love}»"];
                continue;
            }

            if (isset($existingNos[$itemNo])) {
                $skipped++;
                $tableRows[] = [$itemNo, $category, $name, 'skip: уже есть (в т.ч. удалённые)'];
                continue;
            }

            $purchasedAt = null;
            $purchasedAtValue = $row['purchased_at'] ?? $row['purchasedAt'] ?? null;
            if (!empty($purchasedAtValue)) {
                $purchasedAt = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $purchasedAtValue) ?: null;
                if (!$purchasedAt) {
                    $skipped++;
                    $tableRows[] = [$itemNo, $category, $name, "skip: неверная дата покупки «{$purchasedAtValue}»"];
                    continue;
                }
            }

            $price = null;
            if (isset($row['price']) && $row['price'] !== null && $row['price'] !== '') {
                $price = number_format((float) $row['price'], 2, '.', '');
            }

            $created++;
            $tableRows[] = [$itemNo, $category, $name, 'create'];

            if ($dryRun) {
                continue;
            }

            $item = (new WardrobeItem())
                ->setUser($user)
                ->setWardrobe($this->wardrobeManager->getOrCreateDefault($user))
                ->setItemNo($itemNo)
                ->setCategory($category)
                ->setName($name)
                ->setSize($this->nullableString($row['size'] ?? null))
                ->setPrice($price)
                ->setPurchasedAt($purchasedAt)
                ->setProductUrl($this->nullableString($row['product_url'] ?? $row['productUrl'] ?? null))
                ->setNotes($this->nullableString($row['notes'] ?? null))
                ->setPurchaseReason($this->nullableString($row['purchase_reason'] ?? $row['purchaseReason'] ?? null))
                ->setLoveAtFirstSight($love)
                ->setCustomBrandName($this->nullableString($row['custom_brand_name'] ?? $row['customBrandName'] ?? null))
                ->setColorName($this->nullableString($row['color_name'] ?? $row['colorName'] ?? null))
                ->setMaterialText($this->nullableString($row['material_text'] ?? $row['materialText'] ?? null))
                ->setCountryOfOrigin($this->nullableString($row['country_of_origin'] ?? $row['countryOfOrigin'] ?? null))
                ->setSeason($this->nullableString($row['season'] ?? null))
                ->setCareText($this->nullableString($row['care_text'] ?? $row['careText'] ?? null))
                ->setPros($this->nullableString($row['pros'] ?? null))
                ->setCons($this->nullableString($row['cons'] ?? null))
                ->setVerdict($this->nullableString($row['verdict'] ?? null))
                ->setSource(WardrobeItem::SOURCE_IMPORT);

            $this->wardrobeManager->refreshCompletionStatus($item);
            $this->em->persist($item);
            $existingNos[$itemNo] = true; // дедуп внутри одного прогона
        }

        $io->table(['item_no', 'category', 'name', 'action'], $tableRows);

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('Создано %d, пропущено %d%s', $created, $skipped, $dryRun ? ' (dry-run — ничего не сохранено)' : ''));

        return Command::SUCCESS;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
