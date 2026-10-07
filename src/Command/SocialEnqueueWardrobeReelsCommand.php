<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:social:enqueue-wardrobe-reels', description: 'Импорт готовых локальных рилсов: один в день, без повторов')]
class SocialEnqueueWardrobeReelsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialChannelRepository $channels,
        private readonly SocialPostRepository $posts,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manifest', InputArgument::REQUIRED, 'manifest.json локального рендера')
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Первый слот YYYY-MM-DD, 19:00 МСК')
            ->addOption('schedule', null, InputOption::VALUE_NONE, 'Сохранить scheduled-посты; иначе только проверка');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = fopen($this->projectDir . '/var/wardrobe-reels-queue.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->error('Другой импорт ещё выполняется.');
            return Command::FAILURE;
        }

        try {
            $start = (string) $input->getOption('start');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('Europe/Moscow'));
            if (!$date || $date->format('Y-m-d') !== $start || $date->setTime(19, 0) <= new \DateTimeImmutable()) {
                throw new \InvalidArgumentException('--start должен задавать будущий слот в формате YYYY-MM-DD.');
            }
            $file = (string) $input->getArgument('manifest');
            if (!is_file($file)) {
                throw new \InvalidArgumentException('Манифест не найден.');
            }
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $entries = isset($data['id']) ? [$data] : $data;
            if (!is_array($entries) || $entries === [] || count($entries) > 30) {
                throw new \InvalidArgumentException('Ожидается от 1 до 30 готовых роликов.');
            }
            $channel = $this->channels->findOneBy(['platform' => SocialChannel::PLATFORM_IG, 'enabled' => true]);
            if ($channel === null) {
                throw new \RuntimeException('Нет активного Instagram-канала.');
            }

            $pending = [];
            $seen = [];
            foreach ($entries as $index => $entry) {
                $id = $entry['id'] ?? '';
                // Две кампании: старые 6 серий (wardrobe-v1) и шаблоны фото+плашка (wardrobe-templates-v1, id tNN-slug-vN).
                $isTemplate = is_string($id) && preg_match('/^t\d{2}-[a-z0-9]+(?:-[a-z0-9]+)*-v[1-9]$/D', $id) === 1;
                $campaign = $isTemplate ? 'wardrobe-templates-v1' : 'wardrobe-v1';
                if (!is_string($id) || (!$isTemplate && !preg_match('/^(digitize|family|morning|capsule|requests|lifecycle)-v[1-5]$/D', $id))
                    || ($entry['campaign'] ?? null) !== $campaign
                    || !preg_match('/^[a-f0-9]{64}$/D', $entry['fingerprint'] ?? '')
                    || ($entry['duration_ms'] ?? 0) < 3000 || ($entry['duration_ms'] ?? 0) > 60000
                    || !is_string($entry['caption'] ?? null) || mb_strlen($entry['caption']) > 2200
                    || trim($entry['caption']) === '' || ($entry['cta_url'] ?? null) !== 'https://wearbase.ru/ru/wardrobe') {
                    throw new \InvalidArgumentException('Некорректный манифест ролика #' . $index);
                }
                // Черновик (нет ассетов/реальных переменных/шаблон не ready) в очередь публикации не попадает никогда.
                if ($isTemplate && (($entry['draft'] ?? true) !== false
                    || ($entry['assets_missing'] ?? []) !== [] || ($entry['variables_missing'] ?? []) !== []
                    || ($entry['template']['status'] ?? null) !== 'ready')) {
                    throw new \InvalidArgumentException('Черновик не публикуется (draft / нет ассетов / шаблон не ready): ' . $id);
                }
                if (isset($seen[$id])) {
                    throw new \InvalidArgumentException('Повтор ролика в манифесте: ' . $id);
                }
                $seen[$id] = true;
                $video = $this->mediaPath((string) ($entry['video'] ?? ''), 'mp4');
                $cover = $this->mediaPath((string) ($entry['cover'] ?? ''), 'jpg');
                $key = $campaign . '.' . $id;
                if ($this->posts->findOneBy(['channel' => $channel, 'rubric' => 'wardrobe_reels', 'scriptKey' => $key])) {
                    $io->text($id . ': уже в очереди, пропуск.');
                    continue;
                }
                // Слот привязан к позиции, поэтому повторный импорт не сдвигает остаток пачки.
                $slot = $date->modify('+' . $index . ' days');
                if ($this->posts->existsForSlot($channel, 'wardrobe_reels', \DateTime::createFromImmutable($slot))) {
                    throw new \RuntimeException('Слот гардероба занят: ' . $slot->format('Y-m-d'));
                }
                $pending[] = (new SocialPost())
                    ->setChannel($channel)->setRubric('wardrobe_reels')
                    ->setStatus(SocialPost::STATUS_SCHEDULED)->setMediaType(SocialPost::MEDIA_REELS)
                    ->setMediaPath($video)->setCoverPath($cover)->setCaption($entry['caption'])
                    ->setScriptKey($key)->setScriptJson(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    ->setVariant('hook_' . substr($id, -1))->setDurationMs((int) $entry['duration_ms'])
                    ->setSlideCount(count($entry['beats'] ?? $entry['scenes'] ?? []))->setAiGenerated((bool) ($entry['ai_generated'] ?? true))
                    ->setCtaLabel('Цифровой гардероб')
                    ->setScheduledAt(\DateTime::createFromImmutable($slot->setTime(19, 0)));
            }
            // Валидируем всю пачку до первой записи в БД.
            foreach ($pending as $post) {
                $io->text($post->getScheduledAt()->format('Y-m-d H:i T') . ' · ' . $post->getScriptKey());
                if ($input->getOption('schedule')) {
                    $this->em->persist($post);
                }
            }
            if ($input->getOption('schedule')) {
                $this->em->flush();
            }
            $io->success(($input->getOption('schedule') ? 'Поставлено в очередь: ' : 'Проверено без записи: ') . count($pending));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function mediaPath(string $path, string $extension): string
    {
        // Разрешаем перенос пакета между Mac и сервером с другим project_dir.
        $offset = strpos($path, '/images/social/');
        $relative = $offset === false ? '' : substr($path, $offset);
        $base = realpath($this->projectDir . '/public_html/images/social');
        $absolute = $relative === '' ? false : realpath($this->projectDir . '/public_html' . $relative);
        if (!$base || !$absolute || !str_starts_with($absolute, $base . DIRECTORY_SEPARATOR)
            || !is_file($absolute) || filesize($absolute) === 0 || pathinfo($absolute, PATHINFO_EXTENSION) !== $extension) {
            throw new \InvalidArgumentException('Медиа отсутствует или вне public_html/images/social: ' . $path);
        }
        return '/images/social' . substr($absolute, strlen($base));
    }
}
