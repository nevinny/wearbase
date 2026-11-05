<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialPost;
use App\Repository\SocialPostRepository;
use App\Social\Castbase\CastbaseClient;
use App\Social\Castbase\CastbaseException;
use App\Social\IgCaption;
use App\Social\Publisher\InstagramPublisher;
use App\Social\SocialAlerts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Передача запланированных постов каналов publish_via=castbase в API castbase: пост уходит ровно одним
 * путём (условный UPDATE scheduled → handed_off), дальше публикует castbase, итог приходит в
 * app:social:castbase-sync. Запускать на Mac (медиа рендерятся там). Подробности — docs/marketing_instagram.md.
 *
 * Повтор после сетевой ошибки: пост остаётся handed_off с castbase_post_id=NULL, следующий запуск шлёт
 * те же Idempotency-Key (wb-sp-{id}, wb-sp-{id}-m{n}, wb-sp-{id}-cover) — castbase вернёт прежнее, дубля нет.
 */
#[AsCommand(name: 'app:social:handoff', description: 'Передать scheduled-посты каналов publish_via=castbase в API castbase')]
class SocialHandoffCommand extends Command
{
    private const MAX_ATTEMPTS = 3;
    private const HORIZON_HOURS = 24;
    private const CAROUSEL_MAX = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialPostRepository $posts,
        private readonly CastbaseClient $castbase,
        private readonly SocialAlerts $alerts,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default::IG_REELS_SHARE_TO_FEED)%')]
        private readonly ?string $shareReelsToFeed = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('post', null, InputOption::VALUE_REQUIRED, 'Только этот пост (id)')
            ->addOption('shadow', null, InputOption::VALUE_NONE, 'Теневой прогон (options.dry_run, отдельный external_ref): контейнер создаётся, публикации нет, статус поста не меняется. Нужен --post')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Сколько постов за запуск', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $shadow = (bool) $input->getOption('shadow');
        $onlyId = $input->getOption('post') !== null ? (int) $input->getOption('post') : null;

        $lock = fopen($this->projectDir . '/var/social_handoff.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->warning('Предыдущий handoff ещё работает — выходим.');
            return Command::SUCCESS;
        }

        if ($shadow) {
            if ($onlyId === null) {
                $io->error('--shadow требует --post=<id>.');
                return Command::FAILURE;
            }
            $post = $this->posts->find($onlyId);
            if ($post === null || !$post->getChannel()->isViaCastbase()) {
                $io->error("Пост #{$onlyId} не найден или канал не publish_via=castbase.");
                return Command::FAILURE;
            }
            try {
                $n = time();
                $ref = 'social_post:' . $post->getId() . ':shadow:' . $n;
                $created = $this->send($post, $ref, 'wb-sp-' . $post->getId() . '-shadow-' . $n, true);
            } catch (CastbaseException|\RuntimeException $e) {
                $io->error($e->getMessage() . ($e instanceof CastbaseException && $e->errorCode === 'unsupported_option'
                    ? ' (dry_run на этом castbase выключен: IG_DRY_RUN_ENABLED)' : ''));
                return Command::FAILURE;
            }
            $io->success(sprintf('Теневой пост создан: castbase %s, ref %s. Итог — событие post.dry_run_ok в ленте.', $created, $ref));
            return Command::SUCCESS;
        }

        $batch = max(1, (int) $input->getOption('batch'));
        $candidates = $this->posts->findForHandoff(new \DateTimeImmutable('+' . self::HORIZON_HOURS . ' hours'));
        if ($onlyId !== null) {
            $candidates = array_values(array_filter($candidates, static fn (SocialPost $p) => $p->getId() === $onlyId));
        }

        $done = 0;
        foreach (array_slice($candidates, 0, $batch) as $post) {
            $id = $post->getId();
            $channelId = $post->getChannel()->getCastbaseChannelId();
            if ($channelId === null || $channelId === '') {
                $io->warning("Пост #{$id}: у канала нет castbase_channel_id — выполни app:castbase:connect-ig. Пропущен.");
                continue;
            }

            if ($post->getStatus() === SocialPost::STATUS_SCHEDULED) {
                if (!$this->posts->claimForHandoff($id, new \DateTimeImmutable())) {
                    $io->text("Пост #{$id}: уже передан другим запуском.");
                    continue;
                }
                $this->em->refresh($post);
            }

            try {
                $castbaseId = $this->send($post, 'social_post:' . $id, 'wb-sp-' . $id, false);
                $post->setCastbasePostId($castbaseId)->setLastError(null)->setClaimedAt(null);
                $done++;
                $io->text("Пост #{$id} → castbase {$castbaseId}");
            } catch (\RuntimeException $e) {
                $this->fail($post, $e);
            }
            $this->em->flush();
            if ($post->getStatus() === SocialPost::STATUS_PUBLISH_FAILED) {
                $this->alerts->publishFailed($post);
            }
        }

        $io->success("Передано в castbase: {$done}");

        return Command::SUCCESS;
    }

    /**
     * Загрузить медиа и создать пост в castbase.
     *
     * @return string id поста в castbase
     * @throws CastbaseException|\RuntimeException
     */
    private function send(SocialPost $post, string $ref, string $key, bool $dryRun): string
    {
        $paths = array_map(fn (string $p) => $this->projectDir . '/public_html' . $p, $post->getMediaPaths());
        $isReels = $post->getMediaType() === SocialPost::MEDIA_REELS;
        if ($paths === [] || $post->getMediaType() === SocialPost::MEDIA_NONE) {
            throw new \RuntimeException('Instagram требует медиа — текстовый пост невозможен.');
        }
        if ($isReels && count($paths) !== 1) {
            throw new \RuntimeException('Reels — ровно один видеофайл, передано ' . count($paths) . '.');
        }
        if (count($paths) > self::CAROUSEL_MAX) {
            throw new \RuntimeException(sprintf('В карусели Instagram максимум %d слайдов, передано %d — слайды не режем.', self::CAROUSEL_MAX, count($paths)));
        }
        foreach ($paths as $path) {
            if (!is_file($path)) {
                throw new \RuntimeException('Нет файла медиа: ' . basename($path));
            }
        }

        // Порядок слайдов = порядок в media_path; ключ медиа привязан к позиции.
        $media = [];
        foreach ($paths as $n => $path) {
            $media[] = ['media_id' => $this->castbase->uploadMedia($path, $key . '-m' . $n), 'role' => 'item'];
        }

        $coverPath = $post->getCoverPath();
        if ($isReels && $coverPath !== null && trim($coverPath) !== '' && is_file($this->projectDir . '/public_html' . $coverPath)) {
            try {
                $media[] = ['media_id' => $this->castbase->uploadMedia($this->projectDir . '/public_html' . $coverPath, $key . '-cover'), 'role' => 'cover'];
            } catch (CastbaseException $e) {
                // Как в локальном публикаторе: негодная обложка не валит пост (IG возьмёт первый кадр),
                // а временный сбой — валит, чтобы повтор ушёл с тем же телом и тем же ключом.
                if ($e->retryable) {
                    throw $e;
                }
            }
        }

        $body = [
            'channel_ids' => [$post->getChannel()->getCastbaseChannelId()],
            'media' => $media,
            'caption' => IgCaption::withCtaLine((string) $post->getCaption(), $post->getCtaLabel()),
            'publish_at' => ($post->getScheduledAt() !== null ? \DateTimeImmutable::createFromInterface($post->getScheduledAt()) : new \DateTimeImmutable())->format(\DATE_ATOM),
            'external_ref' => $ref,
        ];
        $options = [];
        if ($isReels) {
            $options['audio_name'] = InstagramPublisher::REELS_AUDIO_NAME;
            $options['share_to_feed'] = $this->shareReelsToFeed === null || trim($this->shareReelsToFeed) === ''
                ? true
                : filter_var($this->shareReelsToFeed, FILTER_VALIDATE_BOOLEAN);
        }
        if ($dryRun) {
            $options['dry_run'] = true;
        }
        if ($options !== []) {
            $body['options'] = $options;
        }

        try {
            return (string) $this->castbase->createPost($body, $key)['id'];
        } catch (CastbaseException $e) {
            // external_ref уже занят — пост создан раньше (ключ идемпотентности мог устареть, 72 ч): берём его id из detail.
            if ($e->errorCode === 'external_ref_taken' && !$dryRun
                && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $e->getMessage(), $m)) {
                return $m[0];
            }
            throw $e;
        }
    }

    /** Временный сбой — пост остаётся handed_off и уйдёт повтором; постоянный/исчерпанные попытки — publish_failed. */
    private function fail(SocialPost $post, \RuntimeException $e): void
    {
        $attempts = $post->getPublishAttempts() + 1;
        $retryable = $e instanceof CastbaseException && $e->retryable;
        $post->setPublishAttempts($attempts)
            ->setLastError(mb_substr($e->getMessage(), 0, 500))
            ->setClaimedAt(null);
        if (!$retryable || $attempts >= self::MAX_ATTEMPTS) {
            $post->setStatus(SocialPost::STATUS_PUBLISH_FAILED);
        }
    }
}
