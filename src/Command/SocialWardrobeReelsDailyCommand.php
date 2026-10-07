<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialChannel;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use App\Service\Social\WardrobeReelsRotation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Скользящее окно гардеробных рилсов (до --days=60 вперёд, слот 21:00 МСК). Шаги:
 * 1) daily.cjs --render-missing дорендеривает v1 готовых шаблонов, у которых ещё нет манифеста;
 * 2) собираем все готовые манифесты (v1..v4), которых нет среди постов рубрики (в любом статусе — повторов нет);
 * 3) WardrobeReelsRotation раскладывает их по свободным дням (шаблон не чаще раза в 14 дней, сегменты чередуются);
 * 4) enqueue-wardrobe-reels --slots ставит пачку. Дыры, которые нечем закрыть, заполнятся при следующем запуске.
 * Публикует паблишер, не мы. Без готовых роликов — тихо выходит. Mac only (node + chromium + медиа в public_html/images/social).
 */
#[AsCommand(name: 'app:social:wardrobe-reels-daily', description: 'Скользящее окно гардеробных рилсов: заполнить свободные слоты на N дней вперёд')]
class SocialWardrobeReelsDailyCommand extends Command
{
    private const TZ = 'Europe/Moscow';
    private const KEY_PREFIX = 'wardrobe-templates-v1.';
    private const DEFAULT_DAYS = 60;

    public function __construct(
        private readonly SocialChannelRepository $channels,
        private readonly SocialPostRepository $posts,
        private readonly WardrobeReelsRotation $rotation,
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
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Горизонт окна, дней вперёд (включая сегодня)', (string) self::DEFAULT_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать раскладку, ничего не рендерить и не ставить');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $days = max(1, min(60, (int) $input->getOption('days')));
        $channel = $this->channels->findOneBy(['platform' => SocialChannel::PLATFORM_IG, 'enabled' => true]);
        if ($channel === null) {
            $io->warning('Нет активного Instagram-канала.');
            return Command::SUCCESS;
        }

        if (!$dry) {
            $render = new Process([$this->nodeBin ?: '/opt/homebrew/bin/node', 'scripts/wardrobe-reels/daily.cjs', '--render-missing'], $this->projectDir, timeout: 3600);
            $render->run();
            $io->writeln(trim($render->getOutput()));
            if (!$render->isSuccessful()) {
                $io->error(trim($render->getErrorOutput()) ?: 'daily.cjs --render-missing завершился с ошибкой');
                return Command::FAILURE;
            }
        }

        $tz = new \DateTimeZone(self::TZ);
        $now = new \DateTimeImmutable('now', $tz);

        // Любой существующий пост рубрики (включая опубликованные и упавшие) закрывает ролик: повторов нет.
        $used = [];
        $occupied = [];
        $busyDays = [];
        foreach ($this->posts->findBy(['channel' => $channel, 'rubric' => 'wardrobe_reels']) as $post) {
            $key = (string) $post->getScriptKey();
            if (!str_starts_with($key, self::KEY_PREFIX)) {
                continue;
            }
            $id = substr($key, strlen(self::KEY_PREFIX));
            $used[$id] = true;
            $when = $post->getScheduledAt();
            if ($when === null) {
                continue;
            }
            $day = \DateTimeImmutable::createFromInterface($when)->setTimezone($tz)->format('Y-m-d');
            $script = json_decode((string) $post->getScriptJson(), true);
            $occupied[] = ['day' => $day, 'id' => $id, 'segment' => (string) ($script['template']['segment'] ?? '')];
            $busyDays[$day] = true;
        }

        $candidates = $this->readyManifests($used);
        $free = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $now->setTime(0, 0)->modify('+' . $i . ' days');
            if ($day->setTime(21, 0) > $now && !isset($busyDays[$day->format('Y-m-d')])) {
                $free[] = $day->format('Y-m-d');
            }
        }

        $plan = $this->rotation->plan(array_map(static fn (array $c) => ['id' => $c['id'], 'segment' => $c['segment']], $candidates), $free, $occupied);
        if ($plan === []) {
            $io->success(sprintf('Нечего ставить: готовых новых роликов %d, свободных дней %d.', count($candidates), count($free)));
            return Command::SUCCESS;
        }

        $byId = array_column($candidates, null, 'id');
        $batch = [];
        foreach ($plan as $day => $id) {
            $io->writeln($day . ' 21:00 · ' . $id);
            $batch[] = $byId[$id]['entry'];
        }
        if ($dry) {
            $io->success('План: ' . count($plan));
            return Command::SUCCESS;
        }

        @mkdir($this->projectDir . '/var/wardrobe-reels', 0775, true);
        $file = $this->projectDir . '/var/wardrobe-reels/daily-batch.json';
        file_put_contents($file, json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $enqueue = new Process([\PHP_BINARY, '-d', 'memory_limit=512M', 'bin/console', 'app:social:enqueue-wardrobe-reels', $file,
            '--slots', implode(',', array_keys($plan)), '--schedule', '--no-debug'], $this->projectDir, timeout: 300);
        $enqueue->run();
        $io->writeln(trim($enqueue->getOutput()));
        if (!$enqueue->isSuccessful()) {
            $io->error('enqueue отказал: ' . trim($enqueue->getOutput() . $enqueue->getErrorOutput()));
            return Command::FAILURE;
        }
        $io->success('Поставлено в очередь: ' . count($plan));

        return Command::SUCCESS;
    }

    /**
     * Готовые к публикации манифесты из public_html/images/social/wardrobe-templates/*\/manifest.json.
     * Черновики и ролики с отсутствующим медиа пропускаются здесь, чтобы одна битая запись не блокировала всю пачку.
     *
     * @param array<string, true> $used
     *
     * @return list<array{id: string, segment: string, entry: array<string, mixed>}>
     */
    private function readyManifests(array $used): array
    {
        $social = $this->projectDir . '/public_html/images/social';
        $out = [];
        foreach (glob($social . '/wardrobe-templates/*/manifest.json') ?: [] as $file) {
            $entry = json_decode((string) file_get_contents($file), true);
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (!is_string($id) || isset($used[$id]) || ($entry['campaign'] ?? null) !== 'wardrobe-templates-v1'
                || preg_match('/^t\d{2}-[a-z0-9]+(?:-[a-z0-9]+)*-v[1-9]$/D', $id) !== 1
                || ($entry['draft'] ?? true) !== false || ($entry['assets_missing'] ?? []) !== [] || ($entry['variables_missing'] ?? []) !== []
                || ($entry['template']['status'] ?? null) !== 'ready') {
                continue;
            }
            foreach ([(string) ($entry['video'] ?? ''), (string) ($entry['cover'] ?? '')] as $path) {
                $offset = strpos($path, '/images/social/');
                $abs = $offset === false ? '' : $this->projectDir . '/public_html' . substr($path, $offset);
                if (!is_file($abs) || filesize($abs) === 0) {
                    continue 2;
                }
            }
            $out[] = ['id' => $id, 'segment' => (string) ($entry['template']['segment'] ?? ''), 'entry' => $entry];
        }

        return $out;
    }
}
