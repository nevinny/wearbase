<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialChannelRepository;
use App\Service\Social\BrandGalleryImages;
use App\Service\Social\SocialRubrics;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ставит в очередь рубрику brand_gallery — карусель из реальных фото бренда (brand_image).
 * Разовая/повторяемая массовая постановка, а не еженедельная сетка: один пост на бренд,
 * дедуп по (канал, бренд, рубрика), поэтому команду можно гонять повторно — она добирает
 * только новые бренды (напр. после появления фото или снятия off-niche).
 *
 * Гейты бренда: status=active, ниша не off ([[wearbase-niche-gate]]), происхождение не
 * foreign (иностранные бренды не продвигаем), и ≥2 реально существующих файла фото.
 * Порядок — от самых богатых галерей к бедным: лучший контент уходит первым.
 */
#[AsCommand(
    name: 'app:social:enqueue-brand-gallery',
    description: 'Поставить в очередь карусели из фото брендов (brand_image) для канала',
)]
class SocialEnqueueBrandGalleryCommand extends Command
{
    private const RUBRIC = 'brand_gallery';
    private const RUBRIC_REELS = 'brand_reels';

    /**
     * Позиция логотипа зафиксирована: logo_last (решение владельца 2026-07-31 по рекомендации
     * двух независимых проектирований и ревью маркетолога). logo_first ставил первым кадром
     * логотип незнакомой марки (часто с вордмарком-именем) — противоречит хукам «Чей — в конце»
     * (утечка ответа), vision-порядку кадров (вертикальный товарный первым) и Шварцу.
     * Экспериментальный слот отдан сравнению формулировок хука — оно едет на script_key,
     * колонка variant остаётся для истории и будущих экспериментов.
     */
    private const FIXED_VARIANT = SocialPost::VARIANT_LOGO_LAST;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialChannelRepository $channels,
        private readonly SocialRubrics $rubrics,
        private readonly BrandGalleryImages $gallery,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'Площадка канала (ig|tg|vk)', SocialChannel::PLATFORM_IG)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум брендов за прогон (0 = все)', '0')
            ->addOption('per-day', null, InputOption::VALUE_REQUIRED, 'Сколько брендов на день', '2')
            ->addOption('start-in', null, InputOption::VALUE_REQUIRED, 'Через сколько дней от сегодня начать', '1')
            ->addOption('no-reels', null, InputOption::VALUE_NONE, 'Только карусели, без Reels-слайдшоу')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять, только показать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $platform = (string) $input->getOption('platform');
        $limit = max(0, (int) $input->getOption('limit'));
        $perDay = max(1, (int) $input->getOption('per-day'));
        $startIn = max(0, (int) $input->getOption('start-in'));
        $withReels = !$input->getOption('no-reels');
        $dryRun = (bool) $input->getOption('dry-run');

        $channel = $this->channels->findOneBy(['platform' => $platform, 'enabled' => true]);
        if ($channel === null) {
            $io->error("Нет активного канала площадки «{$platform}».");

            return Command::FAILURE;
        }

        $def = $this->rubrics->get(self::RUBRIC);
        if ($def === null) {
            $io->error('Рубрика ' . self::RUBRIC . ' не описана в SocialRubrics.');

            return Command::FAILURE;
        }

        $alreadyQueued = $this->brandIdsAlreadyQueued($channel);
        $candidates = $this->eligibleBrands();

        $io->text(sprintf(
            'Кандидатов по гейтам: %d · уже в очереди канала: %d',
            count($candidates),
            count($alreadyQueued),
        ));

        $tz = new \DateTimeZone('Europe/Moscow');
        $today = new \DateTimeImmutable('today', $tz);

        $queued = [];
        $skippedNoFiles = 0;
        foreach ($candidates as $brand) {
            if (isset($alreadyQueued[$brand->getId()])) {
                continue;
            }

            $slides = $this->gallery->paths($brand);
            if (count($slides) < BrandGalleryImages::MIN_SLIDES) {
                // Строки в БД есть, а файлов на диске нет — такой пост всё равно уйдёт в held.
                $skippedNoFiles++;
                continue;
            }

            $slot = count($queued);
            $day = $today->modify('+' . ($startIn + intdiv($slot, $perDay)) . ' day');
            $variant = self::FIXED_VARIANT;

            $carousel = (new SocialPost())
                ->setChannel($channel)
                ->setBrand($brand)
                ->setRubric(self::RUBRIC)
                ->setMediaType(SocialPost::MEDIA_CAROUSEL)
                ->setVariant($variant)
                ->setScheduledAt(\DateTime::createFromInterface($day->setTime($def['hour'] + ($slot % $perDay), 0)))
                ->setStatus(SocialPost::STATUS_PLANNED);

            if (!$dryRun) {
                $this->em->persist($carousel);
            }

            if ($withReels) {
                $reelsDef = $this->rubrics->get(self::RUBRIC_REELS);
                $reels = (new SocialPost())
                    ->setChannel($channel)
                    ->setBrand($brand)
                    ->setRubric(self::RUBRIC_REELS)
                    ->setMediaType(SocialPost::MEDIA_REELS)
                    ->setVariant($variant)
                    ->setScheduledAt(\DateTime::createFromInterface($day->setTime(($reelsDef['hour'] ?? 19) + ($slot % $perDay), 0)))
                    ->setStatus(SocialPost::STATUS_PLANNED);

                if (!$dryRun) {
                    $this->em->persist($reels);
                }
            }

            $queued[] = [$brand, count($slides), $carousel->getScheduledAt(), $variant];

            if ($limit > 0 && count($queued) >= $limit) {
                break;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $this->report($io, $queued, $skippedNoFiles, $dryRun, $withReels);

        return Command::SUCCESS;
    }

    /**
     * Бренды-кандидаты: активные, в нише, не иностранные, с активными фото.
     * Сортировка — по числу фото (богатые галереи вперёд), затем по id для детерминизма.
     *
     * @return list<Brand>
     */
    private function eligibleBrands(): array
    {
        /** @var list<Brand> $brands */
        $brands = $this->em->createQueryBuilder()
            ->select('b')
            // HIDDEN — счётчик нужен для HAVING/ORDER BY, но из результата его убираем,
            // иначе getResult() вернёт массивы [entity, count] вместо сущностей.
            ->addSelect('COUNT(i.id) AS HIDDEN imgCount')
            ->from(Brand::class, 'b')
            ->join('b.images', 'i')
            ->where('b.status = :active')
            ->andWhere('i.status = :active')
            // Скобки обязательны: без них OR разорвёт цепочку AND (гейты перестанут работать).
            ->andWhere('(b.nicheStatus IS NULL OR b.nicheStatus != :off)')
            ->andWhere('(b.originStatus IS NULL OR b.originStatus != :foreign)')
            ->setParameter('active', Statuses::Active)
            ->setParameter('off', 'off')
            ->setParameter('foreign', 'foreign')
            ->groupBy('b.id')
            ->having('imgCount >= :min')
            ->setParameter('min', BrandGalleryImages::MIN_SLIDES)
            ->orderBy('imgCount', 'DESC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $brands;
    }

    /**
     * Бренды, для которых пост этой рубрики в канале уже есть (в любом статусе) — чтобы
     * повторный прогон не плодил дубли.
     *
     * @return array<int, true>
     */
    private function brandIdsAlreadyQueued(SocialChannel $channel): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.brand) AS brandId')
            ->from(SocialPost::class, 'p')
            ->where('p.channel = :channel')
            ->andWhere('p.rubric IN (:rubrics)')
            ->andWhere('p.brand IS NOT NULL')
            ->setParameter('channel', $channel)
            ->setParameter('rubrics', [self::RUBRIC, self::RUBRIC_REELS])
            ->getQuery()
            ->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['brandId']] = true;
        }

        return $ids;
    }

    /** @param list<array{0: Brand, 1: int, 2: \DateTimeInterface|null, 3: string}> $queued */
    private function report(SymfonyStyle $io, array $queued, int $skippedNoFiles, bool $dryRun, bool $withReels): void
    {
        if ($queued === []) {
            $io->warning('Ничего не поставлено: новых подходящих брендов нет.');

            return;
        }

        $preview = array_slice($queued, 0, 10);
        $io->table(
            ['Бренд', 'Фото', 'Карусель', 'Ветка A/B'],
            array_map(
                static fn (array $row) => [
                    $row[0]->getTitle() . ' (' . $row[0]->getSlug() . ')',
                    (string) $row[1],
                    $row[2]?->format('d.m H:i') ?? '-',
                    $row[3],
                ],
                $preview,
            ),
        );
        if (count($queued) > count($preview)) {
            $io->text(sprintf('… и ещё %d', count($queued) - count($preview)));
        }

        $variants = array_count_values(array_column($queued, 3));
        $last = end($queued);
        $io->success(sprintf(
            '%sБрендов в очереди: %d (постов %d: карусели%s), фото всего %d, очередь до %s. A/B: %s.%s',
            $dryRun ? '[dry-run] ' : '',
            count($queued),
            count($queued) * ($withReels ? 2 : 1),
            $withReels ? ' + Reels' : '',
            array_sum(array_column($queued, 1)),
            $last[2]?->format('d.m.Y') ?? '-',
            implode(', ', array_map(static fn (string $k, int $n) => "{$k} — {$n}", array_keys($variants), $variants)),
            $skippedNoFiles > 0 ? sprintf(' Пропущено без файлов на диске: %d.', $skippedNoFiles) : '',
        ));
    }
}
