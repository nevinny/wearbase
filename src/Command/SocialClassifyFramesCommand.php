<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandImage;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Классификатор кадра brand_image для отбора/порядка слайдов галерей и Reels (vision,
 * ollama gemma4:26b, LOCAL_LLM_MODEL). Без этого карусель/Reels открывались случайным
 * кадром из brand_image — живой инцидент: рилс открылся тёмным горизонтальным лукбуком
 * (человек лежит в дыму), что убивает удержание в первые 1.5с (см. BrandGalleryImages).
 *
 * 4 класса: product_person (вещь на человеке) | product_flat (вещь без человека: раскладка/
 * вешалка/предметка) | scene (лукбук-сцена/атмосфера, вещь не главное) | other (витрина/
 * интерьер/текст/логотип/прочее). Невалидный ответ модели или ошибка запроса — оставляем
 * frame_kind/frame_checked_at в NULL и переходим к следующей картинке (одна плохая картинка
 * не роняет прогон, следующий запуск переклассифицирует её ещё раз).
 *
 * Гейты бренда — как в SocialEnqueueBrandGalleryCommand::eligibleBrands (active, ниша не off,
 * происхождение не foreign): классифицировать кадры off-нишевых/иностранных брендов незачем,
 * их галереи всё равно не уходят в очередь.
 *
 * Конкурентность 1 обеспечивается самой формой команды — один процесс, синхронный цикл без
 * параллельных HTTP-запросов к ollama ([[llm-server-oversubscription]]: сервер один на всех
 * потребителей). Не запускать несколько инстансов одновременно.
 */
#[AsCommand(
    name: 'app:social:classify-frames',
    description: 'Классифицировать кадры brand_image (product_person|product_flat|scene|other) для галерей/Reels',
)]
class SocialClassifyFramesCommand extends Command
{
    private const BATCH = 20;
    private const PUBLIC_PREFIX = '/images/brands/';

    private const SYSTEM_PROMPT = <<<TXT
        Ты классифицируешь фотографию российского бренда одежды для карусели/Reels в Instagram.
        Определи ОДНУ категорию кадра:
        product_person — на фото человек, на котором надета/надет товар бренда (одежда, обувь, аксессуар).
        product_flat — товар БЕЗ человека: раскладка на столе/полу, на вешалке, предметная съёмка.
        scene — атмосферная лукбук-сцена, интерьер, пейзаж, где сам товар не главный объект кадра.
        other — витрина магазина, интерьер без товара, текст/коллаж/логотип, прочее.
        Ответь СТРОГО одним словом, без пояснений и знаков препинания: product_person, product_flat, scene или other.
        TXT;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmService $llm,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум картинок за прогон', '200')
            ->addOption('brand', null, InputOption::VALUE_REQUIRED, 'Только один бренд по ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять — только показать вердикты');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $brandId = $input->getOption('brand') !== null ? (int) $input->getOption('brand') : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $images = $this->candidates($brandId, $limit);
        if ($images === []) {
            $io->success('Нечего классифицировать (всё проверено либо кандидатов нет).');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Классификация кадров: %d картинок%s', count($images), $dryRun ? ' (dry-run)' : ''));

        $counts = [BrandImage::FRAME_PRODUCT_PERSON => 0, BrandImage::FRAME_PRODUCT_FLAT => 0, BrandImage::FRAME_SCENE => 0, BrandImage::FRAME_OTHER => 0];
        $invalid = 0;
        $i = 0;
        $started = microtime(true);

        foreach ($images as $image) {
            $verdict = $this->classify($image);

            if ($verdict === null) {
                $invalid++;
                $io->writeln(sprintf('  <comment>?  #%d (%s)</comment> — невалидный ответ/ошибка, пропуск', $image->getId(), $image->getImage()));
                continue;
            }

            $counts[$verdict]++;
            $io->writeln(sprintf('  <fg=green>%-14s</> #%d (%s)', $verdict, $image->getId(), $image->getImage()));

            if (!$dryRun) {
                $image->setFrameKind($verdict);
                $image->setFrameCheckedAt(new \DateTime());
                if (++$i % self::BATCH === 0) {
                    $this->em->flush();
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $elapsed = microtime(true) - $started;
        $io->newLine();
        $io->table(
            ['product_person', 'product_flat', 'scene', 'other', 'невалидно'],
            [[$counts[BrandImage::FRAME_PRODUCT_PERSON], $counts[BrandImage::FRAME_PRODUCT_FLAT], $counts[BrandImage::FRAME_SCENE], $counts[BrandImage::FRAME_OTHER], $invalid]],
        );
        $io->success(sprintf(
            'Обработано %d за %.1fс (%.1fс/картинку)%s',
            count($images),
            $elapsed,
            $elapsed / count($images),
            $dryRun ? ' [dry-run]' : '',
        ));

        return Command::SUCCESS;
    }

    /** Вердикт для одной картинки. null = невалидный ответ модели ИЛИ ошибка (файл/сеть). */
    private function classify(BrandImage $image): ?string
    {
        $file = $image->getImage();
        if ($file === null || trim($file) === '') {
            return null;
        }

        $absolutePath = $this->projectDir . '/public_html' . self::PUBLIC_PREFIX . $file;
        if (!is_file($absolutePath)) {
            return null;
        }

        try {
            $raw = $this->llm->generateVision(self::SYSTEM_PROMPT, [$absolutePath], local: true);
        } catch (\Throwable) {
            return null; // сервер недоступен/таймаут — не помечаем, следующий прогон повторит
        }

        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z_]/', '', $normalized) ?? '';

        return in_array($normalized, [
            BrandImage::FRAME_PRODUCT_PERSON,
            BrandImage::FRAME_PRODUCT_FLAT,
            BrandImage::FRAME_SCENE,
            BrandImage::FRAME_OTHER,
        ], true) ? $normalized : null;
    }

    /**
     * Кандидаты: активные фото ещё не классифицированных брендов-кандидатов галерей —
     * те же гейты, что у SocialEnqueueBrandGalleryCommand (active, ниша не off, происхождение
     * не foreign). Порядок — по id бренда/картинки для детерминизма между прогонами.
     *
     * @return list<BrandImage>
     */
    private function candidates(?int $brandId, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i')
            ->from(BrandImage::class, 'i')
            ->join('i.brand', 'b')
            ->where('i.status = :active')
            ->andWhere('i.frameCheckedAt IS NULL')
            ->andWhere('b.status = :active')
            // Скобки обязательны: без них OR разорвёт цепочку AND (гейты перестанут работать).
            ->andWhere('(b.nicheStatus IS NULL OR b.nicheStatus != :off)')
            ->andWhere('(b.originStatus IS NULL OR b.originStatus != :foreign)')
            ->setParameter('active', Statuses::Active)
            ->setParameter('off', 'off')
            ->setParameter('foreign', 'foreign')
            ->orderBy('b.id', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit);

        if ($brandId !== null) {
            $qb->andWhere('b.id = :brandId')->setParameter('brandId', $brandId);
        }

        /** @var list<BrandImage> */
        return $qb->getQuery()->getResult();
    }
}
