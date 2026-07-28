<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Восстановление гардероба из бэкапа формата `wearbase.wardrobe` v1 (перенос между
 * инсталляциями: локальная база → прод).
 *
 * ⚠️ НЕ путать с app:wardrobe:import — тот принимает плоский массив вещей и не знает ни
 * про галерею, ни про категории-отношения, ни про владельцев: на этом формате он потерял
 * бы часть данных.
 *
 * Идемпотентность — по тройке (sha256(--source), source_user_id, source_item_id) в
 * wardrobe_import_map. Уже перенесённые вещи ПРОПУСКАЮТСЯ, а не обновляются: молчаливая
 * перезапись карточки, которую человек мог отредактировать на приёмнике, хуже пропуска.
 *
 * Ограничения, которые нельзя обойти без правки сущностей (фиксируются в отчёте):
 *  - `created_at` вещи выставляется конструктором и сеттера не имеет → дата создания
 *    будет датой импорта. Исходная дата печатается в отчёте, чтобы не потеряться.
 *  - `WardrobeTransfer::transferredAt` тоже без сеттера, поэтому бэкап С ПЕРЕДАЧАМИ
 *    отклоняется целиком: восстановить историю с датой «сейчас» — значит соврать про
 *    хронологию, а тихо потерять её ещё хуже. Понадобится — добавим сеттер отдельно.
 *
 * Владельцы задаются ТОЛЬКО явной картой (--owners-map, source_user_id → email
 * существующего пользователя). Пользователи не создаются: импорт в чужой/новый аккаунт
 * должен быть осознанным действием человека, а не побочным эффектом файла.
 *
 *   php bin/console app:wardrobe:restore-backup backup.json \
 *     --owners-map=owners.json --photos-dir=/tmp/photos --source=anna-local-wardrobe --dry-run
 */
#[AsCommand(
    name: 'app:wardrobe:restore-backup',
    description: 'Восстановить гардероб из бэкапа wearbase.wardrobe v1 (идемпотентно, с dry-run)',
)]
class WardrobeRestoreBackupCommand extends Command
{
    private const FORMAT  = 'wearbase.wardrobe';
    private const VERSION = 1;

    /** Префикс публичного URL фото; в БД хранится только имя файла (каталоги даёт SubdirDirectoryNamer). */
    private const PHOTO_URL_PREFIX = '/images/wardrobe/';

    /** Длины строковых колонок WardrobeItem — вход недоверенный, режем на валидации, а не молча в БД. */
    private const MAX_LENGTHS = [
        'name' => 255, 'brand' => 255, 'color' => 100, 'country_of_origin' => 100,
        'season' => 50, 'size' => 50, 'product_url' => 1000, 'category_legacy' => 100,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly WardrobeManager $wardrobeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup', InputArgument::REQUIRED, 'JSON-файл бэкапа (wearbase.wardrobe v1)')
            ->addOption('owners-map', null, InputOption::VALUE_REQUIRED, 'JSON {"source_user_id": "email@на-приёмнике"}')
            ->addOption('photos-dir', null, InputOption::VALUE_REQUIRED, 'Каталог с файлами фотографий из бэкапа')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Стабильное имя источника — ключ идемпотентности')
            ->addOption('renumber-conflicts', null, InputOption::VALUE_NONE, 'Занятые item_no выдавать заново (по умолчанию конфликт = ошибка)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только проверка и план, без записи')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $renumber = (bool) $input->getOption('renumber-conflicts');
        $source  = trim((string) $input->getOption('source'));
        $mapPath = (string) $input->getOption('owners-map');
        $photos  = rtrim((string) $input->getOption('photos-dir'), '/');
        $path    = (string) $input->getArgument('backup');

        $io->title('Гардероб · восстановление из бэкапа' . ($dryRun ? ' (dry-run)' : ''));

        if ($source === '') {
            $io->error('--source обязателен: это ключ идемпотентности, без него повторный запуск создаст дубли.');
            return Command::INVALID;
        }

        try {
            $backup     = $this->readJson($path, 'бэкап');
            $ownersMap  = $this->readJson($mapPath, 'карта владельцев');
            $this->assertFormat($backup);

            $owners = $this->resolveOwners($backup['owners'], $ownersMap);
            $io->section('Владельцы');
            foreach ($owners as $sourceId => $user) {
                $io->text(sprintf('  #%d → %s (id %d)', $sourceId, (string) $user->getEmail(), (int) $user->getId()));
            }

            $plan = $this->plan($backup['items'], $owners, $photos, $source, $renumber);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->section('План');
        $io->text(sprintf('файл: %s', $path));
        $io->text(sprintf('sha256: %s', hash_file('sha256', $path) ?: '—'));
        $io->text(sprintf('версия формата: %s v%d, экспорт от %s', self::FORMAT, self::VERSION, (string) ($backup['exported_at'] ?? '—')));
        $io->text(sprintf('вещей в бэкапе: %d · к созданию: %d · уже перенесено: %d', count($backup['items']), count($plan['create']), $plan['skipped']));
        $io->text(sprintf('фотографий к созданию: %d (уникальных файлов: %d)', $plan['photos'], count($plan['files'])));
        $io->text('передач: 0 (бэкап с передачами отклоняется — см. докблок команды)');
        if ($plan['renumbered'] !== []) {
            $io->text(sprintf('перенумеровано (номер был занят): %d', count($plan['renumbered'])));
            foreach ($plan['renumbered'] as $line) {
                $io->text('  · ' . $line);
            }
        }

        if ($plan['create'] === []) {
            $io->success('Создавать нечего: всё из этого источника уже перенесено.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('dry-run: ни одной записи не создано.');
            $this->printSummary($io, $plan, true);

            return Command::SUCCESS;
        }

        try {
            $created = $this->import($plan, $source);
        } catch (\Throwable $e) {
            $io->error('Импорт откачен: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Перенесено вещей: %d', $created));
        $this->printSummary($io, $plan, false);

        return Command::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path, string $what): array
    {
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException(sprintf('Не найден %s: %s', $what, $path === '' ? '(путь не задан)' : $path));
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('%s не разобран как JSON-объект: %s', ucfirst($what), $path));
        }

        return $data;
    }

    /** @param array<string,mixed> $backup */
    private function assertFormat(array $backup): void
    {
        if (($backup['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException(sprintf('Чужой формат файла: ожидался «%s», получен «%s».', self::FORMAT, (string) ($backup['format'] ?? '—')));
        }
        if (($backup['version'] ?? null) !== self::VERSION) {
            // Неизвестная версия — стоп до любого импорта: поля могли переехать.
            throw new \RuntimeException(sprintf('Версия формата %s не поддерживается (умеем v%d).', json_encode($backup['version'] ?? null), self::VERSION));
        }
        if (!is_array($backup['owners'] ?? null) || !is_array($backup['items'] ?? null)) {
            throw new \RuntimeException('В бэкапе должны быть массивы owners и items.');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $owners
     * @param array<string,mixed> $ownersMap
     * @return array<int,User> source_user_id → пользователь приёмника
     */
    private function resolveOwners(array $owners, array $ownersMap): array
    {
        $resolved = [];
        $errors   = [];
        foreach ($owners as $owner) {
            $sourceId = (int) ($owner['source_user_id'] ?? 0);
            if ($sourceId <= 0) {
                $errors[] = 'в owners есть запись без source_user_id';
                continue;
            }
            $email = trim((string) ($ownersMap[(string) $sourceId] ?? ''));
            if ($email === '') {
                $errors[] = sprintf('владелец #%d (%s) не сопоставлен в --owners-map', $sourceId, (string) ($owner['name'] ?? '—'));
                continue;
            }
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user === null) {
                $errors[] = sprintf('пользователь %s (для владельца #%d) не найден — импортёр не создаёт аккаунты', $email, $sourceId);
                continue;
            }
            $resolved[$sourceId] = $user;
        }

        if ($errors !== []) {
            throw new \RuntimeException("Владельцы не сопоставлены:\n  • " . implode("\n  • ", $errors));
        }

        return $resolved;
    }

    /**
     * Полная валидация + план. Ошибки собираем ВСЕ разом: чинить бэкап по одной ошибке
     * за прогон — худший способ переносить чужие данные.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,User> $owners
     * @return array{create:list<array<string,mixed>>,skipped:int,photos:int,files:array<string,true>,renumbered:list<string>}
     */
    private function plan(array $items, array $owners, string $photosDir, string $source, bool $renumber): array
    {
        $errors      = [];
        $renumbered  = [];
        $create      = [];
        $skipped     = 0;
        $photoCount  = 0;
        $files       = [];
        $usedItemNos = [];   // занимаемые в этом же прогоне номера — ловим дубли внутри бэкапа
        $fingerprint = hash('sha256', $source);

        foreach ($items as $index => $item) {
            $sourceItemId = (int) ($item['source_item_id'] ?? 0);
            $label        = sprintf('вещь #%s «%s»', $sourceItemId ?: ('idx' . $index), mb_substr((string) ($item['name'] ?? '—'), 0, 40));

            if ($sourceItemId <= 0) {
                $errors[] = "$label: нет source_item_id";
                continue;
            }

            $ownerSourceId = (int) ($item['owner']['source_user_id'] ?? 0);
            $owner         = $owners[$ownerSourceId] ?? null;
            if ($owner === null) {
                $errors[] = "$label: владелец #$ownerSourceId отсутствует в owners бэкапа";
                continue;
            }

            if (($item['transfers'] ?? []) !== []) {
                $errors[] = "$label: есть история передач, а её нельзя восстановить с исходными датами (см. докблок)";
                continue;
            }

            if ($this->alreadyImported($fingerprint, $ownerSourceId, $sourceItemId)) {
                $skipped++;
                continue;
            }

            // Статусы и enum'ы
            foreach ([
                'completion_status' => [WardrobeItem::COMPLETION_DRAFT, WardrobeItem::COMPLETION_BASIC, WardrobeItem::COMPLETION_COMPLETE],
                'item_status'       => [WardrobeItem::ITEM_ACTIVE, WardrobeItem::ITEM_REPAIR, WardrobeItem::ITEM_ARCHIVED, WardrobeItem::ITEM_SOLD, WardrobeItem::ITEM_DONATED, WardrobeItem::ITEM_TRANSFERRED, WardrobeItem::ITEM_LOST],
                'wear_status'       => [WardrobeItem::WEAR_ACTIVE, WardrobeItem::WEAR_RESERVE, WardrobeItem::WEAR_OUTGROWN, WardrobeItem::WEAR_GIVEN_AWAY],
            ] as $field => $allowed) {
                $value = $item[$field] ?? null;
                if ($value !== null && !in_array($value, $allowed, true)) {
                    $errors[] = sprintf('%s: недопустимый %s = «%s»', $label, $field, (string) $value);
                }
            }
            $love = $item['love_at_first_sight'] ?? null;
            if ($love !== null && !in_array($love, [WardrobeItem::LOVE_YES, WardrobeItem::LOVE_NO, WardrobeItem::LOVE_UNKNOWN], true)) {
                $errors[] = sprintf('%s: недопустимый love_at_first_sight = «%s»', $label, (string) $love);
            }

            // Длины строк
            foreach (self::MAX_LENGTHS as $field => $max) {
                $value = $field === 'category_legacy' ? ($item['category']['name'] ?? null) : ($item[$field] ?? null);
                if (is_string($value) && mb_strlen($value) > $max) {
                    $errors[] = sprintf('%s: поле %s длиннее %d символов', $label, $field, $max);
                }
            }

            // Цена — только как строка-decimal, без прогона через float
            $price = $item['price'] ?? null;
            if ($price !== null && preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', (string) $price) !== 1) {
                $errors[] = sprintf('%s: цена «%s» не похожа на decimal', $label, (string) $price);
            }

            // Даты
            $purchasedAt = null;
            if (($item['purchased_at'] ?? null) !== null) {
                try {
                    $purchasedAt = new \DateTimeImmutable((string) $item['purchased_at']);
                } catch (\Throwable) {
                    $errors[] = sprintf('%s: не разобрана дата покупки «%s»', $label, (string) $item['purchased_at']);
                }
            }

            // Категория: основной ключ — code, имя только как legacy-строка
            $categoryRef  = null;
            $categoryCode = $item['category']['code'] ?? null;
            if (is_string($categoryCode) && $categoryCode !== '') {
                $categoryRef = $this->em->getRepository(WardrobeCategory::class)->findOneBy(['code' => $categoryCode]);
                if ($categoryRef === null) {
                    $errors[] = sprintf('%s: категория с code «%s» не найдена — категории на приёмнике не создаём', $label, $categoryCode);
                }
            }

            // Номер вещи: свободен ли у этого владельца (учитывая soft-deleted и этот же прогон)
            $itemNo   = (int) ($item['item_no'] ?? 0);
            $ownerKey = (int) $owner->getId();
            if ($itemNo > 0) {
                $taken = isset($usedItemNos[$ownerKey][$itemNo]) || $this->itemNoTaken($owner, $itemNo);
                if ($taken && !$renumber) {
                    $errors[] = sprintf('%s: номер %d у %s уже занят (--renumber-conflicts выдаст свободный)', $label, $itemNo, (string) $owner->getEmail());
                } elseif ($taken) {
                    // Номер — порядковый ярлык внутри пользователя, а не идентичность вещи:
                    // на приёмнике человек мог завести свои карточки, и они заняли номера.
                    $free = $this->nextFreeItemNo($owner, $usedItemNos[$ownerKey] ?? []);
                    $renumbered[] = sprintf('%s: %d → %d', mb_substr((string) ($item['name'] ?? '—'), 0, 30), $itemNo, $free);
                    $itemNo = $free;
                    $usedItemNos[$ownerKey][$itemNo] = true;
                } else {
                    $usedItemNos[$ownerKey][$itemNo] = true;
                }
            }

            // Фото: путь внутри каталога, файл на месте, дубли схлопываем
            $itemPhotos = [];
            foreach (($item['photos'] ?? []) as $photo) {
                $name = $this->safePhotoName((string) ($photo['url'] ?? ''));
                if ($name === null) {
                    $errors[] = sprintf('%s: небезопасный или чужой путь фото «%s»', $label, (string) ($photo['url'] ?? ''));
                    continue;
                }
                if (!is_file($photosDir . '/' . $this->photoSubPath($name))) {
                    $errors[] = sprintf('%s: файл фото не найден: %s', $label, $this->photoSubPath($name));
                    continue;
                }
                // Один и тот же файл приходит и как legacy-обложка, и как элемент галереи —
                // это след миграции старых обложек, а не две разные фотографии.
                if (isset($itemPhotos[$name])) {
                    $itemPhotos[$name]['cover'] = $itemPhotos[$name]['cover'] || (bool) ($photo['cover'] ?? false);
                    continue;
                }
                $itemPhotos[$name] = [
                    'name'  => $name,
                    'type'  => $this->photoType((string) ($photo['type'] ?? '')),
                    'cover' => (bool) ($photo['cover'] ?? false),
                ];
                $files[$name] = true;
            }
            $photoCount += count($itemPhotos);

            $create[] = [
                'source_item_id' => $sourceItemId,
                'source_user_id' => $ownerSourceId,
                'owner'          => $owner,
                'item'           => $item,
                'item_no'        => $itemNo,
                'category_ref'   => $categoryRef,
                'purchased_at'   => $purchasedAt,
                'photos'         => array_values($itemPhotos),
            ];
        }

        if ($errors !== []) {
            throw new \RuntimeException(sprintf("Бэкап не прошёл проверку (%d), импорт не запускался:\n  • %s", count($errors), implode("\n  • ", $errors)));
        }

        return ['create' => $create, 'skipped' => $skipped, 'photos' => $photoCount, 'files' => $files, 'renumbered' => $renumbered];
    }

    /**
     * Имя файла из публичного URL: только внутри /images/wardrobe/, без обхода каталога.
     * Каталоги (aa/bb) не хранятся в БД — их детерминированно даёт SubdirDirectoryNamer.
     */
    private function safePhotoName(string $url): ?string
    {
        if ($url === '' || !str_starts_with($url, self::PHOTO_URL_PREFIX)) {
            return null;
        }
        $relative = substr($url, strlen(self::PHOTO_URL_PREFIX));
        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }

        $name = basename($relative);

        // Путь обязан совпадать с тем, что вычислит namer: иначе файл лёг бы не туда,
        // куда потом пойдёт vich_uploader_asset.
        return $name !== '' && $this->photoSubPath($name) === $relative ? $name : null;
    }

    /** aa/bb/name.webp — SubdirDirectoryNamer (chars_per_dir=2, dirs=2, см. vich_uploader.yaml). */
    private function photoSubPath(string $name): string
    {
        return sprintf('%s/%s/%s', mb_substr($name, 0, 2), mb_substr($name, 2, 2), $name);
    }

    private function photoType(string $type): string
    {
        $known = [
            WardrobeItemPhoto::TYPE_COVER, WardrobeItemPhoto::TYPE_PRODUCT, WardrobeItemPhoto::TYPE_BACK,
            WardrobeItemPhoto::TYPE_DETAIL, WardrobeItemPhoto::TYPE_LABEL, WardrobeItemPhoto::TYPE_CARE,
            WardrobeItemPhoto::TYPE_RECEIPT,
        ];

        // legacy — не тип фотографии, а пометка «пришло из старого поля обложки»
        return in_array($type, $known, true) ? $type : WardrobeItemPhoto::TYPE_COVER;
    }

    private function alreadyImported(string $fingerprint, int $sourceUserId, int $sourceItemId): bool
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM wardrobe_import_map
             WHERE source_fingerprint = ? AND source_user_id = ? AND source_item_id = ?',
            [$fingerprint, $sourceUserId, $sourceItemId],
        ) > 0;
    }

    /**
     * Следующий свободный номер: максимум из уже занятых в БД (включая soft-deleted —
     * номер за ними закреплён) и занятых в этом же прогоне.
     *
     * @param array<int,true> $usedInRun
     */
    private function nextFreeItemNo(User $owner, array $usedInRun): int
    {
        $maxDb = (int) $this->db->fetchOne(
            'SELECT COALESCE(MAX(item_no), 0) FROM wardrobe_item WHERE user_id = ?',
            [(int) $owner->getId()],
        );
        $maxRun = $usedInRun === [] ? 0 : max(array_keys($usedInRun));

        return max($maxDb, $maxRun) + 1;
    }

    private function itemNoTaken(User $owner, int $itemNo): bool
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM wardrobe_item WHERE user_id = ? AND item_no = ?',
            [(int) $owner->getId(), $itemNo],
        ) > 0;
    }

    /**
     * Одна транзакция на весь перенос: частично приехавший чужой гардероб хуже, чем
     * неприехавший — его потом не отличить от того, что человек завёл руками.
     *
     * @param array{create:list<array<string,mixed>>,skipped:int,photos:int,files:array<string,true>,renumbered:list<string>} $plan
     */
    private function import(array $plan, string $source): int
    {
        $fingerprint = hash('sha256', $source);
        $now         = new \DateTimeImmutable();

        return $this->em->wrapInTransaction(function () use ($plan, $fingerprint, $now): int {
            $created = 0;

            foreach ($plan['create'] as $row) {
                /** @var User $owner */
                $owner = $row['owner'];
                /** @var array<string,mixed> $src */
                $src = $row['item'];

                $item = (new WardrobeItem())
                    ->setUser($owner)
                    ->setWardrobe($this->wardrobeManager->getOrCreateDefault($owner))
                    ->setName($this->str($src['name'] ?? null))
                    ->setCustomBrandName($this->str($src['brand'] ?? null))
                    ->setColorName($this->str($src['color'] ?? null))
                    ->setSize($this->str($src['size'] ?? null))
                    ->setMaterialText($this->str($src['material'] ?? null))
                    ->setCountryOfOrigin($this->str($src['country_of_origin'] ?? null))
                    ->setSeason($this->str($src['season'] ?? null))
                    ->setPrice($this->str($src['price'] ?? null))
                    ->setPurchasedAt($row['purchased_at'])
                    ->setPurchaseReason($this->str($src['purchase_reason'] ?? null))
                    ->setLoveAtFirstSight($this->str($src['love_at_first_sight'] ?? null))
                    ->setCareText($this->str($src['care'] ?? null))
                    ->setPros($this->str($src['pros'] ?? null))
                    ->setCons($this->str($src['cons'] ?? null))
                    ->setVerdict($this->str($src['verdict'] ?? null))
                    ->setNotes($this->str($src['notes'] ?? null))
                    ->setProductUrl($this->str($src['product_url'] ?? null))
                    ->setCompletionStatus((string) ($src['completion_status'] ?? WardrobeItem::COMPLETION_DRAFT))
                    ->setItemStatus((string) ($src['item_status'] ?? WardrobeItem::ITEM_ACTIVE))
                    ->setWearStatus((string) ($src['wear_status'] ?? WardrobeItem::WEAR_ACTIVE))
                    ->setSource(WardrobeItem::SOURCE_IMPORT);

                if ($row['item_no'] > 0) {
                    $item->setItemNo($row['item_no']);
                }
                if ($row['category_ref'] instanceof WardrobeCategory) {
                    $item->setCategoryRef($row['category_ref']);
                }
                // Legacy-строка категории: и как fallback для карточек без code, и как
                // подпись, которую показывают старые шаблоны.
                $item->setCategory($this->str($src['category']['name'] ?? null));

                $this->em->persist($item);

                $sort = 0;
                foreach ($row['photos'] as $photo) {
                    $entity = (new WardrobeItemPhoto())
                        ->setItem($item)
                        ->setFilePath($photo['name'])
                        ->setPhotoType($photo['type'])
                        ->setIsCover($photo['cover'])
                        ->setSource(WardrobeItemPhoto::SOURCE_IMPORT)
                        ->setSortOrder($sort++);
                    $item->addPhoto($entity);
                    $this->em->persist($entity);
                }

                $this->em->flush(); // нужен id вещи для карты импорта

                $this->db->insert('wardrobe_import_map', [
                    'source_fingerprint' => $fingerprint,
                    'source_user_id'     => $row['source_user_id'],
                    'source_item_id'     => $row['source_item_id'],
                    'wardrobe_item_id'   => (int) $item->getId(),
                    'imported_at'        => $now->format('Y-m-d H:i:s'),
                ]);

                $created++;
            }

            return $created;
        });
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /** @param array{create:list<array<string,mixed>>,skipped:int,photos:int,files:array<string,true>,renumbered:list<string>} $plan */
    private function printSummary(SymfonyStyle $io, array $plan, bool $dryRun): void
    {
        $io->section('Итог (машиночитаемо)');
        $io->writeln(json_encode([
            'dry_run'        => $dryRun,
            'items_planned'  => count($plan['create']),
            'items_skipped'  => $plan['skipped'],
            'photos'         => $plan['photos'],
            'unique_files'   => count($plan['files']),
            'renumbered'     => $plan['renumbered'],
            'transfers'      => 0,
            'limitations'    => ['created_at вещи = дата импорта (сеттера нет в сущности)'],
        ], JSON_UNESCAPED_UNICODE));
    }
}
