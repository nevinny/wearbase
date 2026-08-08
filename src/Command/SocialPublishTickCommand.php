<?php

namespace App\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Notification\AdminNotifier;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use App\Social\Publisher\SocialPublisherRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Дрип-публикация запланированных постов (часовой cron на нужном egress-хосте).
 * Темп уже задан scheduled_at (планировщик ~1 пост/день/канал); рамп здесь —
 * ПРЕДОХРАНИТЕЛЬНЫЙ дневной потолок + 24ч-квота площадки (IG 25/24ч).
 *
 * Per-host: VK-тик на проде, TG+IG-тик на Mac (egress). Берёт из очереди только посты
 * каналов своего host. См. docs/marketing_instagram.md §4.
 */
#[AsCommand(name: 'app:social:publish-tick', description: 'Дрип-публикация scheduled-постов (cron)')]
class SocialPublishTickCommand extends Command
{
    private const MAX_PUBLISH_ATTEMPTS = 3;
    private const STALE_MINUTES = 60;

    /** Хард-квота медиа/24ч по площадке (предохранитель). */
    private const QUOTA_24H = [
        SocialChannel::PLATFORM_IG => 25,
        SocialChannel::PLATFORM_TG => 50,
        SocialChannel::PLATFORM_VK => 50,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialChannelRepository $channels,
        private readonly SocialPostRepository $posts,
        private readonly SocialPublisherRegistry $registry,
        private readonly AdminNotifier $notifier,
        private readonly \App\Service\Social\RampSchedule $ramp,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(SOCIAL_LAUNCH_DATE)%')]
        private readonly string $launchDate,
        #[Autowire('%env(int:SOCIAL_START_RATE)%')]
        private readonly int $startRate,
        #[Autowire('%env(int:SOCIAL_CAP)%')]
        private readonly int $cap,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'egress-хост: mac|prod', SocialChannel::HOST_MAC)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Сколько постов за тик', '10')
            ->addOption('post', null, InputOption::VALUE_REQUIRED, 'Опубликовать конкретный пост сейчас, не дожидаясь расписания (ручная проверка)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Посчитать лимиты, не публиковать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $batch = max(1, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');

        $lock = fopen($this->projectDir . "/var/social_publish_tick_{$host}.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->warning('Предыдущий тик ещё работает — выходим.');
            return Command::SUCCESS;
        }

        if (!$dryRun) {
            $this->posts->reclaimStale(self::STALE_MINUTES);
        }

        // Бюджет публикаций на этот тик по каждому каналу host (дневной потолок ∩ 24ч-квота).
        $budget = [];
        foreach ($this->channels->findEnabledByHost($host) as $ch) {
            $dailyCap = $this->rampTarget($ch);
            $remainingDay = max(0, $dailyCap - $this->posts->countPublishedToday($ch));
            $quota = self::QUOTA_24H[$ch->getPlatform()] ?? 50;
            $remaining24 = max(0, $quota - $this->posts->countPublishedLast24h($ch));
            $budget[$ch->getId()] = min($remainingDay, $remaining24);
            $io->text(sprintf('%s [%s]: дневной потолок %d, бюджет тика %d', $ch->getName(), $ch->getPlatform(), $dailyCap, $budget[$ch->getId()]));
        }

        if ($budget === []) {
            $io->text("Нет включённых каналов на host={$host}.");
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('dry-run — без публикации');
            return Command::SUCCESS;
        }

        $onePost = $input->getOption('post');
        $claimed = $onePost !== null
            ? $this->posts->claimOne((int) $onePost)
            : $this->posts->claimDue($host, $batch);

        if ($claimed === []) {
            $io->text($onePost !== null
                ? "Пост #{$onePost} не найден или он не в статусе scheduled."
                : 'Нет готовых к публикации постов (scheduled, время подошло).');
            return Command::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        $lines = [];
        foreach ($claimed as $post) {
            $ch = $post->getChannel();
            $chId = $ch->getId();

            if (($budget[$chId] ?? 0) <= 0) {
                // Бюджет канала исчерпан — вернуть в очередь на следующий тик (с flush, иначе застрянет в publishing).
                $post->setStatus(SocialPost::STATUS_SCHEDULED)->setClaimedAt(null);
                $this->em->flush();
                continue;
            }

            try {
                $mediaAbs = array_map(
                    fn (string $path) => $this->projectDir . '/public_html' . $path,
                    $post->getMediaPaths(),
                );

                $externalId = $this->registry->get($ch->getPlatform())
                    ->publish($ch, $post, $mediaAbs);

                $post->setStatus(SocialPost::STATUS_PUBLISHED)
                    ->setPublishedAt(new \DateTime())
                    ->setExternalId($externalId)
                    ->setClaimedAt(null)
                    ->setLastError(null);
                $budget[$chId]--;
                $done++;
                $lines[] = sprintf('• %s [%s] #%d', $ch->getName(), $ch->getPlatform(), $post->getId());
            } catch (\Throwable $e) {
                $attempts = $post->getPublishAttempts() + 1;
                $post->setPublishAttempts($attempts)
                    ->setLastError(mb_substr($e->getMessage(), 0, 500))
                    ->setClaimedAt(null)
                    ->setStatus($attempts >= self::MAX_PUBLISH_ATTEMPTS
                        ? SocialPost::STATUS_PUBLISH_FAILED
                        : SocialPost::STATUS_SCHEDULED);
                $failed++;
            }
            $this->em->flush();
        }

        if ($done > 0 && $this->notifier->isEnabled()) {
            try {
                $this->notifier->send("📣 <b>Соцсети: опубликовано {$done}</b>\n" . implode("\n", $lines));
            } catch (\Throwable) {
                // уведомление не должно ломать публикацию
            }
        }

        $io->success(sprintf('Опубликовано %d, ошибок %d (host=%s)', $done, $failed, $host));

        return Command::SUCCESS;
    }

    /** Ramp-up дневной потолок канала (формула — в RampSchedule, ради тестируемости). */
    private function rampTarget(SocialChannel $channel): int
    {
        $launch = $channel->getLaunchDate()?->format('Y-m-d') ?? ($this->launchDate ?: null);

        return $this->ramp->dailyTarget(
            $channel->getRateStart() ?? $this->startRate,
            $channel->getRateCap() ?? $this->cap,
            $launch,
        );
    }
}
