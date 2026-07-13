<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SocialPost;
use App\Entity\SocialPostMetric;
use App\Repository\SocialPostMetricRepository;
use App\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Closed-loop (Ф0, docs/social_value_plan.md): нет токена API Метрики → источник кликов —
 * nginx-логи прода (combined, ретеншен ~10 дней). Тянем строки с `utm_medium=social` через
 * ssh+zgrep (читает и .gz, и текущий access.log), атрибуируем к посту через `utm_content=p<id>`
 * (CaptionGenerator::withUtm) с fallback на платформу+рубрику+окно публикации, и копим
 * `linkTaps` в SocialPostMetric — читает SocialEvaluateCommand.
 *
 * Инкрементально: для каждого поста берём последний снимок (measuredAt) и считаем только
 * клики строго ПОЗЖЕ него — так повторный прогон при перекрывающихся логах не дублирует.
 * measuredAt нового снимка = максимальный timestamp обработанного клика (не now()) —
 * идемпотентно независимо от расхождения часов Mac/прод.
 */
#[AsCommand(name: 'app:social:ingest-clicks', description: 'Инжест UTM-кликов соцсетей из nginx-логов прода (closed-loop)')]
class SocialIngestClicksCommand extends Command
{
    /** Клик старее published_at поста дальше этого окна — не атрибуируем (fallback-путь). */
    private const MAX_ATTRIBUTION_AGE_DAYS = 14;

    private const SSH_TIMEOUT_SEC = 60;

    /** UI-превью/боты/скрипты — не реальные клики покупателя. TelegramBot-превью отсекается по «bot». */
    private const BOT_UA_RE = '/bot|spider|crawl|preview|vkshare|whatsapp|curl|wget|python|go-http|facebookexternalhit|snippet/i';

    /** Комбинированный (combined) формат access-лога nginx. */
    private const LOG_LINE_RE = '/^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>\S+) (?<path>\S+)[^"]*" (?<status>\d{3}) \S+ "(?<referer>[^"]*)" "(?<ua>[^"]*)"/';

    private const ALLOWED_STATUSES = ['200', '301', '302'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialPostRepository $posts,
        private readonly SocialPostMetricRepository $metrics,
        #[Autowire('%env(SOCIAL_CLICK_LOG_HOST)%')]
        private readonly string $sshHost,
        #[Autowire('%env(SOCIAL_CLICK_LOG_GLOB)%')]
        private readonly string $logGlob,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Читать лог из локального файла вместо ssh (тесты/отладка)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getOption('file');

        $raw = $file !== null ? $this->readLocalFile((string) $file, $io) : $this->readViaSsh($io);
        if ($raw === null) {
            return Command::SUCCESS; // ошибка ssh/файла уже отрапортована, не валим крон
        }

        $lines = $raw === '' ? [] : explode("\n", trim($raw));

        $read = 0;
        $skipped = 0;
        $bots = 0;
        // Кандидаты кликов, сгруппированные по id поста (после атрибуции).
        /** @var array<int,list<array{ts:\DateTimeImmutable,ip:string}>> $byPost */
        $byPost = [];
        $unattributed = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $read++;

            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                $skipped++;
                continue;
            }

            if ($parsed['method'] !== 'GET' || !in_array($parsed['status'], self::ALLOWED_STATUSES, true)) {
                $skipped++;
                continue;
            }

            if (preg_match(self::BOT_UA_RE, $parsed['ua']) === 1) {
                $bots++;
                continue;
            }

            $query = $this->extractQuery($parsed['path']);
            if (!isset($query['utm_medium']) || $query['utm_medium'] !== 'social') {
                $skipped++;
                continue;
            }

            $post = $this->attribute($query, $parsed['time']);
            if ($post === null) {
                $unattributed++;
                continue;
            }

            $postId = $post->getId();
            $byPost[$postId] ??= [];
            $byPost[$postId][] = ['ts' => $parsed['time'], 'ip' => $parsed['ip']];
        }

        $updatedPosts = 0;
        foreach ($byPost as $postId => $clicks) {
            $post = $this->posts->find($postId);
            if ($post === null) {
                continue;
            }

            $latest = $this->metrics->findLatestForPost($post);
            $threshold = $latest?->getMeasuredAt();

            // Только клики строго позже последнего снимка (инкремент) + дедуп по (ip, день).
            $seen = [];
            $maxTs = null;
            $newCount = 0;
            foreach ($clicks as $click) {
                if ($threshold !== null && $click['ts'] <= $threshold) {
                    continue;
                }
                $dedupKey = $click['ip'] . '|' . $click['ts']->format('Y-m-d');
                if (isset($seen[$dedupKey])) {
                    continue;
                }
                $seen[$dedupKey] = true;
                $newCount++;
                if ($maxTs === null || $click['ts'] > $maxTs) {
                    $maxTs = $click['ts'];
                }
            }

            if ($newCount === 0) {
                continue;
            }

            $snapshot = new SocialPostMetric();
            $snapshot->setPost($post)
                ->setReach($latest?->getReach() ?? 0)
                ->setSaves($latest?->getSaves() ?? 0)
                ->setShares($latest?->getShares() ?? 0)
                ->setLikes($latest?->getLikes() ?? 0)
                ->setComments($latest?->getComments() ?? 0)
                ->setLinkTaps(($latest?->getLinkTaps() ?? 0) + $newCount)
                ->setMeasuredAt(\DateTime::createFromInterface($maxTs));
            $this->em->persist($snapshot);
            $updatedPosts++;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Строк: %d, скип %d, боты %d, атрибуировано %d, без атрибуции %d, постов обновлено %d',
            $read,
            $skipped,
            $bots,
            array_sum(array_map('count', $byPost)),
            $unattributed,
            $updatedPosts,
        ));

        return Command::SUCCESS;
    }

    /** ssh+zgrep на прод: одна команда, читает и текущий access.log, и .gz-ротацию. */
    private function readViaSsh(SymfonyStyle $io): ?string
    {
        $remoteCmd = 'zgrep -h -- "utm_medium=social" ' . $this->logGlob;
        $process = new Process(['ssh', $this->sshHost, $remoteCmd], timeout: self::SSH_TIMEOUT_SEC);
        $process->run();

        // zgrep: exit 1 = ничего не найдено (не ошибка), exit >1 = реальный сбой.
        $exitCode = (int) $process->getExitCode();
        if ($exitCode > 1) {
            $io->warning(sprintf('ssh/zgrep завершились с кодом %d: %s', $exitCode, trim($process->getErrorOutput())));
            return null;
        }

        return $process->getOutput();
    }

    private function readLocalFile(string $path, SymfonyStyle $io): ?string
    {
        if (!is_file($path)) {
            $io->warning("Файл не найден: {$path}");
            return null;
        }

        return (string) file_get_contents($path);
    }

    /**
     * Разбор одной строки combined-лога. null — строка не распознана (скип).
     * @return array{ip:string,time:\DateTimeImmutable,method:string,path:string,status:string,ua:string}|null
     */
    private function parseLine(string $line): ?array
    {
        if (preg_match(self::LOG_LINE_RE, $line, $m) !== 1) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m['time']);
        if ($time === false) {
            return null;
        }
        // Нормализуем к UTC — офсет лога (+0300, regru) даёт правильный абсолютный момент,
        // а хранить/сравнивать удобнее в единой TZ (совпадает с default php.ini UTC приложения).
        $time = $time->setTimezone(new \DateTimeZone('UTC'));

        return [
            'ip'     => $m['ip'],
            'time'   => $time,
            'method' => $m['method'],
            'path'   => $m['path'],
            'status' => $m['status'],
            'ua'     => $m['ua'],
        ];
    }

    /** @return array<string,string> */
    private function extractQuery(string $pathWithQuery): array
    {
        $q = strpos($pathWithQuery, '?');
        if ($q === false) {
            return [];
        }
        parse_str(substr($pathWithQuery, $q + 1), $query);

        /** @var array<string,string> $query */
        return $query;
    }

    /**
     * Атрибуция клика к посту. 1) utm_content=p<id> — точное совпадение (проверяем, что пост
     * существует и опубликован); если тег есть, но невалиден/не найден — НЕ откатываемся на
     * fallback (тег явно указывал на конкретный пост, угадывать другой было бы неверно).
     * 2) без utm_content — платформа(utm_source) + рубрика(utm_campaign) + окно публикации.
     */
    private function attribute(array $query, \DateTimeImmutable $clickAt): ?SocialPost
    {
        $content = $query['utm_content'] ?? '';
        if ($content !== '') {
            if (preg_match('/^p(\d+)$/', $content, $m) !== 1) {
                return null;
            }
            $post = $this->posts->find((int) $m[1]);
            if ($post === null || !in_array($post->getStatus(), [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_DONE], true)) {
                return null;
            }
            return $post;
        }

        $source = $query['utm_source'] ?? '';
        $campaign = $query['utm_campaign'] ?? '';
        if ($source === '' || $campaign === '') {
            return null;
        }

        return $this->posts->findForClickAttribution($source, $campaign, $clickAt, self::MAX_ATTRIBUTION_AGE_DAYS);
    }
}
