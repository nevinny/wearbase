<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Entity\SocialPostMetric;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostMetricRepository;
use App\Repository\SocialPostRepository;
use App\Service\SecretCipher;
use App\Service\Social\InstagramInsights;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Коллектор метрик Instagram (closed-loop, докармливает app:social:evaluate).
 * Оркестрирует: находит опубликованные IG-посты канала своего egress-хоста, дёргает
 * InstagramInsights и пишет новый append-only снимок SocialPostMetric — сам не знает,
 * как устроен запрос к Graph API (InstagramInsights) и не хранит бизнес-логику
 * инкремента кликов (переносит linkTaps из последнего снимка, как ingest-clicks).
 *
 * Per-host, как publish-tick: TG/IG недоступны с РФ-прода → host=mac по умолчанию.
 * Ошибка по одному посту не должна ронять весь прогон (сеть Meta нестабильна).
 */
#[AsCommand(name: 'app:social:collect-metrics', description: 'Собирает метрики (insights) опубликованных IG-постов (closed-loop)')]
class SocialCollectMetricsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialChannelRepository $channels,
        private readonly SocialPostRepository $posts,
        private readonly SocialPostMetricRepository $metrics,
        private readonly InstagramInsights $insights,
        private readonly SecretCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'egress-хост: mac|prod', SocialChannel::HOST_MAC)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум постов за прогон', '50')
            ->addOption('max-age-days', null, InputOption::VALUE_REQUIRED, 'Не собирать метрики постов старше N дней', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать что нашлось бы, без записи снимков');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $limit = max(1, (int) $input->getOption('limit'));
        $maxAgeDays = max(1, (int) $input->getOption('max-age-days'));
        $dryRun = (bool) $input->getOption('dry-run');

        // Insights пока умеет только Instagram — VK/TG-каналы этого хоста не трогаем.
        $channels = array_values(array_filter(
            $this->channels->findEnabledByHost($host),
            static fn (SocialChannel $c) => $c->getPlatform() === SocialChannel::PLATFORM_IG,
        ));

        if ($channels === []) {
            $io->text("Нет включённых IG-каналов на host={$host}.");
            return Command::SUCCESS;
        }

        $since = new \DateTime("-{$maxAgeDays} days");
        $posts = $this->posts->findPublishedForMetrics($channels, $since, $limit);

        if ($posts === []) {
            $io->text('Нет опубликованных постов, подходящих под критерии.');
            return Command::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        foreach ($posts as $post) {
            try {
                $snapshot = $this->collectOne($post);
                $ok++;

                if ($dryRun) {
                    $io->text(sprintf(
                        '#%d [%s]: reach=%d likes=%d comments=%d shares=%d saves=%d views=%d avg_watch_ms=%d (dry-run, не сохранено)',
                        $post->getId(), $post->getMediaType(), $snapshot->getReach(), $snapshot->getLikes(),
                        $snapshot->getComments(), $snapshot->getShares(), $snapshot->getSaves(),
                        $snapshot->getViews(), $snapshot->getAvgWatchMs(),
                    ));
                    continue;
                }

                $this->em->persist($snapshot);
                $this->em->flush();

                $io->text(sprintf(
                    '#%d [%s]: reach=%d likes=%d comments=%d shares=%d saves=%d views=%d avg_watch_ms=%d',
                    $post->getId(), $post->getMediaType(), $snapshot->getReach(), $snapshot->getLikes(),
                    $snapshot->getComments(), $snapshot->getShares(), $snapshot->getSaves(),
                    $snapshot->getViews(), $snapshot->getAvgWatchMs(),
                ));
            } catch (\Throwable $e) {
                $failed++;
                $io->warning(sprintf('#%d: %s', $post->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf('Собрано метрик: %d, ошибок: %d (host=%s)', $ok, $failed, $host));

        return Command::SUCCESS;
    }

    /** Снимок для одного поста (не сохраняет — persist/flush решает вызывающий, dry-run это ценит). */
    private function collectOne(SocialPost $post): SocialPostMetric
    {
        $mediaId = (string) $post->getExternalId();
        $channel = $post->getChannel();

        $enc = $channel?->getTokenEnc();
        if ($enc === null || $enc === '') {
            throw new \RuntimeException('У IG-канала нет токена.');
        }
        $token = $this->cipher->decrypt($enc);

        $isReels = $post->getMediaType() === SocialPost::MEDIA_REELS;
        $values = $this->insights->fetch($mediaId, $isReels, $token);

        // Append-only: новый снимок копирует linkTaps последнего (их считает ingest-clicks,
        // не insights) и меняет только свою размерность — иначе затрём накопленные клики.
        $latest = $this->metrics->findLatestForPost($post);

        return (new SocialPostMetric())
            ->setPost($post)
            ->setReach($values['reach'] ?? 0)
            ->setLikes($values['likes'] ?? 0)
            ->setComments($values['comments'] ?? 0)
            ->setShares($values['shares'] ?? 0)
            ->setSaves($values['saved'] ?? 0)
            ->setViews($values['views'] ?? 0)
            ->setAvgWatchMs($values['ig_reels_avg_watch_time'] ?? 0)
            ->setLinkTaps($latest?->getLinkTaps() ?? 0)
            ->setMeasuredAt(new \DateTime());
    }
}
