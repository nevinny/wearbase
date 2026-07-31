<?php

namespace App\Command;

use App\Entity\Brand;
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
use App\Service\Social\SlideScript;
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
                // Сценарий слайдов считается ДО подписи: CaptionGenerator читает уже проставленные
                // script_key/script_json, чтобы построить первую строку подписи по ступени лестницы
                // хуков («{Город}. Угадай город...» и т.п.) — без этого пришлось бы либо гадать
                // ступень заново в CaptionGenerator (риск разойтись с реальным сценарием), либо
                // звать LLM за фактами дважды.
                $galleryScript = $this->prepareGalleryScript($post, $def);

                $this->captions->compose($post, $def);
                // Все подписи теперь пишет LLM (ядро — на привязанных фактах, бренд — из описания).
                $post->setAiGenerated(true);
                $post->setGenerateAttempts($post->getGenerateAttempts() + 1);

                // Карусель и Reels строятся из фото бренда, а не из сгенерированной картинки.
                if ($def['media'] === SocialPost::MEDIA_CAROUSEL) {
                    $slides = $this->gallerySlides($post, $galleryScript);
                    $post->setMediaPaths($slides);
                    $post->setMediaType(count($slides) >= BrandGalleryImages::MIN_SLIDES
                        ? SocialPost::MEDIA_CAROUSEL
                        : SocialPost::MEDIA_NONE);
                } elseif ($def['media'] === SocialPost::MEDIA_REELS) {
                    $slides = $this->gallerySlides($post, $galleryScript);
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

                // Биты из LLM (b.rag*/b.mix*) — первая партия на ручной просмотр: детерминированные
                // факты (год/категории/материал/маркетплейс) уже проверены руками один раз здесь, а
                // сгенерированные моделью — нет, и ошибка попала бы прямо в паблик Instagram.
                if ($this->needsManualReview($post->getScriptKey())) {
                    $this->hold($post, 'Биты из LLM на слайдах — ручной просмотр первой партии перед публикацией.');
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
     * Сценарий надписей карусели/Reels — считается ДО подписи (CaptionGenerator читает
     * script_key/script_json уже проставленными) и ДО рендера медиа, чтобы карусель и Reels
     * одного бренда переиспользовали ОДИН текст, а не звали LLM за фактами дважды (второй вызов
     * дал бы другие факты — модель недетерминирована).
     *
     * @param array{day:int,hour:int,source:string,needsBrand:bool,media:string,auto:bool,hashtags:string[]} $def
     *
     * @return array{sources: list<string>, script: SlideScript}|null null — нечего рендерить
     *         (нет бренда/фото), qaReason() ниже уведёт такой пост в held
     */
    private function prepareGalleryScript(SocialPost $post, array $def): ?array
    {
        if (!in_array($def['media'], [SocialPost::MEDIA_CAROUSEL, SocialPost::MEDIA_REELS], true)) {
            return null;
        }

        $brand = $post->getBrand();
        if ($brand === null) {
            return null;
        }

        $sources = $this->gallery->paths($brand);
        if ($sources === []) {
            return null;
        }

        $script = $this->resolveScript($brand, count($sources) + 1);
        $post->setScriptKey($script->scriptKey);
        $post->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        return ['sources' => $sources, 'script' => $script];
    }

    /**
     * Переиспользовать сценарий последнего поста ЭТОГО бренда, если он уже есть (карусель и
     * Reels бренда обязаны получить один текст), иначе собрать новый.
     */
    private function resolveScript(Brand $brand, int $totalSlides): SlideScript
    {
        $existing = $this->posts->findLatestScriptForBrand($brand);
        if ($existing !== null) {
            $data = json_decode((string) $existing->getScriptJson(), true);
            if (is_array($data)) {
                return SlideScript::fromArray($data);
            }
        }

        return $this->scripts->compose($brand, $totalSlides);
    }

    /**
     * Слайды поста: фото бренда из brand_image, приведённые к одному холсту, плюс слайд с
     * логотипом — первым или последним по ветке A/B (SocialPost::VARIANT_*, по умолчанию
     * логотип последним). $prepared === null уводит пост в held ниже, в qaReason.
     *
     * @param array{sources: list<string>, script: SlideScript}|null $prepared
     *
     * @return list<string>
     */
    private function gallerySlides(SocialPost $post, ?array $prepared): array
    {
        if ($prepared === null) {
            return [];
        }

        $slides = $this->slides->render(
            $post,
            $prepared['sources'],
            $post->getVariant() === SocialPost::VARIANT_LOGO_FIRST,
            $prepared['script'],
        );
        $post->setSlideCount(count($slides));

        return $slides;
    }

    /**
     * Биты из LLM (грамотный источник — 'rag'/'mix' в сегменте b.*) требуют ручного просмотра
     * первой партии: детерминированные факты уже проверялись руками, сгенерированные моделью —
     * нет, а провал прямо ведёт в паблик Instagram.
     */
    private function needsManualReview(?string $scriptKey): bool
    {
        if ($scriptKey === null) {
            return false;
        }

        $bitsSegment = explode('|', $scriptKey)[1] ?? '';

        return str_starts_with($bitsSegment, 'b.rag') || str_starts_with($bitsSegment, 'b.mix');
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
