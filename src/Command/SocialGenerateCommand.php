<?php

namespace App\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialPostRepository;
use App\Service\ContentValidator;
use App\Service\Social\CaptionGenerator;
use App\Service\Social\MediaRenderer;
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
                $caption = $this->captions->compose($post, $def);
                $post->setCaption($caption);
                $post->setAiGenerated($def['source'] === SocialRubrics::SOURCE_LLM);
                $post->setGenerateAttempts($post->getGenerateAttempts() + 1);

                $mediaPath = $this->media->render($post);
                $post->setMediaPath($mediaPath);
                if ($mediaPath === null) {
                    $post->setMediaType(SocialPost::MEDIA_NONE);
                }

                $reason = $this->qaReason($post, $caption, $mediaPath);
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

    /** Причина увести пост в held (null = всё ок). */
    private function qaReason(SocialPost $post, string $caption, ?string $mediaPath): ?string
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

        // Instagram не принимает текстовые посты — нужна картинка/видео.
        if ($post->getChannel()?->getPlatform() === SocialChannel::PLATFORM_IG && $mediaPath === null) {
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
