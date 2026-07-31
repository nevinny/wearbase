<?php

namespace App\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialPostRepository;
use App\Service\ContentValidator;
use App\Service\Social\BrandGalleryImages;
use App\Service\Social\CaptionGenerator;
use App\Service\Social\CardImageRenderer;
use App\Service\Social\GallerySlideRenderer;
use App\Service\Social\MediaRenderer;
use App\Service\Social\ReelsSlideshowRenderer;
use App\Service\Social\SlideScriptComposer;
use App\Service\Social\SocialRubrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Наполнение запланированных постов: подпись (CaptionGenerator) + медиа (MediaRenderer) + QA.
 * Успех → scheduled (ждёт publish-tick); провал QA / нет медиа для IG → held (ручной просмотр).
 */
#[AsCommand(name: 'app:social:generate', description: 'Сгенерировать подпись+медиа для planned-постов')]
class SocialGenerateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialPostRepository $posts,
        private readonly SocialRubrics $rubrics,
        private readonly CaptionGenerator $captions,
        private readonly MediaRenderer $media,
        private readonly CardImageRenderer $cardRenderer,
        private readonly BrandGalleryImages $gallery,
        private readonly GallerySlideRenderer $slides,
        private readonly ReelsSlideshowRenderer $reels,
        private readonly SlideScriptComposer $scripts,
        private readonly ContentValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько постов за прогон', '20')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var SocialPost[] $batch */
        $batch = $this->posts->findBy(['status' => SocialPost::STATUS_PLANNED], ['scheduledAt' => 'ASC'], $limit);
        if ($batch === []) {
            $io->text('Нет planned-постов.');
            return Command::SUCCESS;
        }

        $ok = 0;
        $held = 0;
        foreach ($batch as $post) {
            $def = $this->rubrics->get($post->getRubric());
            if ($def === null) {
                $this->hold($post, 'Неизвестная рубрика: ' . $post->getRubric());
                $held++;
                continue;
            }

            try {
                $this->captions->compose($post, $def);
                // Все подписи теперь пишет LLM (ядро — на привязанных фактах, бренд — из описания).
                $post->setAiGenerated(true);
                $post->setGenerateAttempts($post->getGenerateAttempts() + 1);

                // Карусель и Reels строятся из фото бренда, а не из сгенерированной картинки.
                if ($def['media'] === SocialPost::MEDIA_CAROUSEL) {
                    $slides = $this->gallerySlides($post);
                    $post->setMediaPaths($slides);
                    $post->setMediaType(count($slides) >= BrandGalleryImages::MIN_SLIDES
                        ? SocialPost::MEDIA_CAROUSEL
                        : SocialPost::MEDIA_NONE);
                } elseif ($def['media'] === SocialPost::MEDIA_REELS) {
                    $slides = $this->gallerySlides($post);
                    $video = count($slides) >= BrandGalleryImages::MIN_SLIDES
                        ? $this->reels->render($post, $slides)
                        : null;
                    $post->setMediaPath($video);
                    // Обложка — первый ФОТО-слайд: одинаковая в обеих ветках A/B, иначе у
                    // logo_first обложкой во вкладке Reels становится карточка логотипа.
                    $post->setCoverPath($video !== null ? $this->slides->coverSlide($post) : null);
                    $post->setMediaType($video !== null ? SocialPost::MEDIA_REELS : SocialPost::MEDIA_NONE);
                } else {
                    $mediaPath = $this->media->render($post);

                    // Доп. ветка (не меняет TG/VK и остальные рубрики): для IG рубрики-шаблоны
                    // получают брендированную карточку с заголовком вместо слабой AI-сцены
                    // (см. docs/marketing_instagram.md §5).
                    if ($post->getChannel()?->getPlatform() === SocialChannel::PLATFORM_IG
                        && $this->cardRenderer->supports($post->getRubric())
                    ) {
                        $cardPath = $this->cardRenderer->render($post);
                        if ($cardPath !== null) {
                            $mediaPath = $cardPath;
                        }
                    }

                    $post->setMediaPath($mediaPath);
                    // Тип медиа = факт: есть картинка → image, иначе none (для текст-рубрик без карточки).
                    $post->setMediaType($mediaPath !== null ? SocialPost::MEDIA_IMAGE : SocialPost::MEDIA_NONE);
                }

                $reason = $this->qaReason($post, (string) $post->getCaption(), $post->getMediaPaths());
                if ($reason !== null) {
                    $this->hold($post, $reason);
                    $held++;
                    continue;
                }

                $post->setStatus(SocialPost::STATUS_SCHEDULED);
                $post->setLastError(null);
                $ok++;
            } catch (\Throwable $e) {
                $post->setStatus(SocialPost::STATUS_GENERATE_FAILED);
                $post->setLastError(mb_substr($e->getMessage(), 0, 500));
                $post->setGenerateAttempts($post->getGenerateAttempts() + 1);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%sГотово: scheduled=%d, held=%d из %d', $dryRun ? '[dry-run] ' : '', $ok, $held, count($batch)));

        return Command::SUCCESS;
    }

    /**
     * Слайды поста: фото бренда из brand_image, приведённые к одному холсту, плюс слайд с
     * логотипом — первым или последним по ветке A/B (SocialPost::VARIANT_*, по умолчанию
     * логотип последним). Пустой список уводит пост в held ниже, в qaReason.
     *
     * @return list<string>
     */
    private function gallerySlides(SocialPost $post): array
    {
        $brand = $post->getBrand();
        if ($brand === null) {
            return [];
        }

        $sources = $this->gallery->paths($brand);
        if ($sources === []) {
            return [];
        }

        // Надписи — сценарий по канону удержания внимания, а не поисковая фраза
        // (SlideScriptComposer: хук, удерживающая реплика, CTA одной связкой).
        // Сид = id бренда: карусель и Reels одного бренда получают одинаковый текст, ветки A/B —
        // тоже одинаковый (иначе эксперимент сравнивал бы заодно и копию), а соседние бренды в
        // ленте — разные формулировки.
        $script = $this->scripts->compose($brand, (int) $brand->getId());

        return $this->slides->render(
            $post,
            $sources,
            $post->getVariant() === SocialPost::VARIANT_LOGO_FIRST,
            $script,
        );
    }

    /**
     * Причина увести пост в held (null = всё ок).
     *
     * @param list<string> $mediaPaths
     */
    private function qaReason(SocialPost $post, string $caption, array $mediaPaths): ?string
    {
        $trimmed = trim($caption);
        if ($trimmed === '' || mb_strlen($trimmed) > 2000) {
            return 'Подпись пустая или длиннее 2000 символов';
        }

        foreach ($this->validator->getAiPhrases() as $phrase) {
            if (mb_stripos($caption, $phrase) !== false) {
                return "AI-фраза в подписи: '{$phrase}'";
            }
        }

        // Карусель без пары слайдов — не карусель: у бренда нет фото на диске.
        $def = $this->rubrics->get($post->getRubric());
        if ($def !== null
            && $def['media'] === SocialPost::MEDIA_CAROUSEL
            && count($mediaPaths) < BrandGalleryImages::MIN_SLIDES
        ) {
            return sprintf('Для карусели нужно ≥%d фото бренда, найдено %d', BrandGalleryImages::MIN_SLIDES, count($mediaPaths));
        }

        // Instagram не принимает текстовые посты — нужна картинка/видео.
        if ($post->getChannel()?->getPlatform() === SocialChannel::PLATFORM_IG && $mediaPaths === []) {
            return 'Instagram требует медиа, а его нет (рубрика-карточка/Reels — на ручную)';
        }

        return null;
    }

    private function hold(SocialPost $post, string $reason): void
    {
        $post->setStatus(SocialPost::STATUS_HELD);
        $post->setLastError($reason);
    }
}
