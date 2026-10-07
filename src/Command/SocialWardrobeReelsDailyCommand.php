<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialChannel;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Скользящее окно гардеробных рилсов: на каждый свободный слот 21:00 МСК ближайших N дней рендерит
 * следующий готовый шаблон (daily.cjs --next) и ставит в очередь enqueue-командой. Публикует паблишер, не мы.
 * Без готовых шаблонов — тихо ничего не делает. Mac only (node + chromium + медиа в public_html/images/social).
 */
#[AsCommand(name: 'app:social:wardrobe-reels-daily', description: 'Скользящее окно гардеробных рилсов: дорендерить и поставить в очередь до N дней вперёд')]
class SocialWardrobeReelsDailyCommand extends Command
{
    private const TZ = 'Europe/Moscow';
    private const KEY_PREFIX = 'wardrobe-templates-v1.';

    public function __construct(
        private readonly SocialChannelRepository $channels,
        private readonly SocialPostRepository $posts,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default::NODE_BIN)%')]
        private readonly ?string $nodeBin = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Горизонт окна, дней вперёд (включая сегодня)', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что было бы поставлено');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, min(30, (int) $input->getOption('days')));
        $channel = $this->channels->findOneBy(['platform' => SocialChannel::PLATFORM_IG, 'enabled' => true]);
        if ($channel === null) {
            $io->warning('Нет активного Instagram-канала.');
            return Command::SUCCESS;
        }

        // Любой существующий пост рубрики (включая опубликованные и упавшие) закрывает шаблон: повторов нет.
        $used = [];
        foreach ($this->posts->findBy(['channel' => $channel, 'rubric' => 'wardrobe_reels']) as $post) {
            $key = (string) $post->getScriptKey();
            if (str_starts_with($key, self::KEY_PREFIX)) {
                $used[] = substr($key, strlen(self::KEY_PREFIX));
            }
        }

        $tz = new \DateTimeZone(self::TZ);
        $now = new \DateTimeImmutable('now', $tz);
        $queued = 0;
        for ($i = 0; $i < $days; $i++) {
            $day = $now->setTime(0, 0)->modify('+' . $i . ' days');
            if ($day->setTime(21, 0) <= $now
                || $this->posts->existsForSlot($channel, 'wardrobe_reels', \DateTime::createFromImmutable($day))) {
                continue;
            }
            $args = [$this->nodeBin ?: '/opt/homebrew/bin/node', 'scripts/wardrobe-reels/daily.cjs', '--next', '--date', $day->format('Y-m-d'), '--skip', implode(',', $used)];
            if ($input->getOption('dry-run')) {
                $args[] = '--plan';
            } else {
                $args[] = '--schedule';
            }
            $process = new Process($args, $this->projectDir, ['PHP_BIN' => \PHP_BINARY], timeout: 1800);
            $process->run();
            $io->writeln(trim($process->getOutput()));
            if (!$process->isSuccessful()) {
                $io->error(trim($process->getErrorOutput()) ?: 'daily.cjs завершился с ошибкой');
                return Command::FAILURE;
            }
            if (!preg_match('/^Next: №\d+ (\S+)/m', $process->getOutput(), $m)) {
                break; // готовых неопубликованных шаблонов больше нет
            }
            $used[] = 't' . $m[1] . '-v1';
            ++$queued;
        }
        $io->success(($input->getOption('dry-run') ? 'План: ' : 'Поставлено в очередь: ') . $queued);

        return Command::SUCCESS;
    }
}
