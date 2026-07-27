<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Service\LlmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Авторевью открытых PR локальной моделью (ollama, LOCAL_LLM_MODEL).
 *
 * Почему командой на Mac, а не GitHub Action: раннер GitHub живёт в облаке и до
 * ollama в домашней LAN не достаёт, а self-hosted раннер на публичном репозитории —
 * это чужой код на твоей машине. Поэтому инициатива у Mac: крон-диспетчер дёргает
 * команду, она сама ходит в GitHub через уже авторизованный `gh`.
 *
 * Идемпотентность без своей таблицы: под ревью бот оставляет в теле комментария
 * скрытый маркер `<!-- local-review:<sha> -->`. Есть маркер с текущим head-SHA —
 * PR пропускается; новый пуш меняет SHA и ревью повторяется.
 *
 * GPU: flock `var/review_pr.lock` + LOCK_NB. RAG-демон грузит ту же карту, а
 * переподписка роняет gemma (память llm-server-oversubscription) — если ревью
 * уже идёт, второй экземпляр молча уходит.
 *
 *   php bin/console app:review:pr --dry-run        # показать вердикт, не постить
 *   php bin/console app:review:pr --pr=55
 *   php bin/console app:review:pr --no-debug       # крон
 */
#[AsCommand(
    name: 'app:review:pr',
    description: 'Ревью открытых PR локальной LLM: комментарий в PR + пинг в Telegram',
)]
class ReviewPrCommand extends Command
{
    private const GH_BIN = '/opt/homebrew/bin/gh';

    /** Дальше этого диффы не влезают в контекст модели — режем и помечаем в промпте. */
    private const DIFF_LIMIT = 40000;

    /** Шум, который не несёт смысла для ревью и съедает контекст. */
    private const SKIP_FILES = ['composer.lock', 'package-lock.json', 'symfony.lock', '.DS_Store'];

    /** @var resource|null держим открытым, иначе GC снимет flock */
    private $lockHandle = null;

    public function __construct(
        private readonly LlmService $llm,
        private readonly AdminNotifier $notifier,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pr', null, InputOption::VALUE_REQUIRED, 'Ревьюить конкретный PR по номеру')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько PR обработать за прогон', '3')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ревьюить, даже если этот SHA уже отревьюен')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать вердикт в консоли, ничего не постить');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $force   = (bool) $input->getOption('force');
        $limit   = max(1, (int) $input->getOption('limit'));

        if (!$this->acquireLock()) {
            $io->warning('Ревью уже идёт (var/review_pr.lock) — выходим, чтобы не переподписать GPU.');

            return Command::SUCCESS;
        }

        $prs = $this->openPullRequests($io, $input->getOption('pr'));
        if ($prs === []) {
            $io->text('Открытых PR нет.');

            return Command::SUCCESS;
        }

        $done = 0;
        foreach ($prs as $pr) {
            if ($done >= $limit) {
                break;
            }
            $number = (int) $pr['number'];
            $sha    = (string) $pr['headRefOid'];
            $author = (string) ($pr['author']['login'] ?? '—');

            if (!$force && $this->alreadyReviewed($number, $sha)) {
                $io->text(sprintf('#%d — уже отревьюен на %s, пропуск.', $number, substr($sha, 0, 7)));
                continue;
            }

            $diff = $this->diff($number);
            if (trim($diff) === '') {
                $io->text(sprintf('#%d — пустой дифф, пропуск.', $number));
                continue;
            }

            $io->section(sprintf('PR #%d — %s (%s)', $number, $pr['title'], $author));
            $verdict = trim($this->llm->generate(
                $this->prompt($pr['title'], $diff),
                self::SYSTEM_PROMPT,
                null,
                600,
                null,
                true,   // local
                false,  // think off — модель иначе сливает рассуждения в ответ
                0.3,    // ревью должно быть воспроизводимым, не творческим
            ));

            if ($verdict === '') {
                $io->warning(sprintf('#%d — модель вернула пустой ответ.', $number));
                continue;
            }

            $io->writeln($verdict);
            ++$done;

            if ($dryRun) {
                continue;
            }

            $this->postComment($number, $sha, $verdict);
            $this->notifier->send(sprintf(
                "%s <b>PR #%d</b> — ревью локальной модели%s\n%s\nАвтор: %s\n%s",
                $this->blockers($verdict) > 0 ? '🔴' : '🔍',
                $number,
                $this->blockers($verdict) > 0 ? sprintf(' · блокеров: %d', $this->blockers($verdict)) : '',
                htmlspecialchars($pr['title'], ENT_QUOTES | ENT_SUBSTITUTE),
                htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE),
                $pr['url'],
            ));
        }

        $io->success(sprintf('Отревьюено PR: %d.', $done));

        return Command::SUCCESS;
    }

    private const SYSTEM_PROMPT = <<<'TXT'
        Ты — придирчивый ревьюер PHP/Symfony-кода проекта WEARBASE. Пишешь по-русски,
        коротко и по делу. Не хвалишь, не пересказываешь дифф, не предлагаешь рефакторинг
        соседнего кода. Если находок нет — пишешь одну строку «Находок нет».
        TXT;

    private function prompt(string $title, string $diff): string
    {
        $truncated = mb_strlen($diff) > self::DIFF_LIMIT
            ? mb_substr($diff, 0, self::DIFF_LIMIT) . "\n\n[…дифф обрезан, показана только первая часть]"
            : $diff;

        return <<<TXT
            Отревьюй дифф pull request «{$title}».

            Правила проекта, нарушение которых — находка:
            - Soft-delete only: физический DELETE по действию пользователя запрещён
              (только status=deleted или deleted_at). Выборки обязаны фильтровать удалённое.
            - Два фаервола и две сущности User: /admin → AdminCore\\Entity\\User, остальное →
              App\\Entity\\User. Смешение — критично.
            - Миграции идемпотентны (CREATE TABLE IF NOT EXISTS / INSERT IGNORE), schema:update
              запрещён. FK на country.id обязан быть INT UNSIGNED.
            - Цены хранятся в рублях, конвертация только на лету (CurrencyConverter, фильтр |price).
            - Шаблоны — единый Tailwind-стек, Bootstrap воскрешать нельзя.
            - Секреты (ключи, токены, chat_id) не должны попадать в код: репозиторий публичный.
            - Лишние абстракции, спекулятивная «гибкость» и правки не по теме PR — тоже находки.

            Формат ответа: список находок, по одной на строку. Строка начинается РОВНО с
            одного эмодзи приоритета — 🔴 блокер, 🟠 важное, 🟡 мелочь — дальше путь к файлу
            со строкой, тире и суть. Пример правильной строки:
            🔴 src/Controller/BrandLkController.php:124 — физический DELETE вместо soft-delete
            Не копируй перечисление приоритетов в ответ, выбирай один. Без вступлений и итогов.

            ДИФФ:
            {$truncated}
            TXT;
    }

    /** @return list<array{number:int,title:string,url:string,headRefOid:string,author:array{login?:string}}> */
    private function openPullRequests(SymfonyStyle $io, ?string $only): array
    {
        $args = ['pr', 'list', '--state', 'open', '--json', 'number,title,url,headRefOid,author'];
        if ($only !== null) {
            $args = ['pr', 'view', $only, '--json', 'number,title,url,headRefOid,author'];
        }

        $json = $this->gh($args);
        if ($json === null) {
            $io->error('gh не отдал список PR — проверь `gh auth status`.');

            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        return $only !== null ? [$data] : $data;
    }

    /** Маркер в комментариях: тот же head-SHA уже ревьюили. */
    private function alreadyReviewed(int $number, string $sha): bool
    {
        $json = $this->gh(['pr', 'view', (string) $number, '--json', 'comments']);
        if ($json === null) {
            return false;
        }

        return str_contains($json, $this->marker($sha));
    }

    private function diff(int $number): string
    {
        $diff = $this->gh(['pr', 'diff', (string) $number]) ?? '';

        // Отсекаем шумные файлы целиком (по секциям `diff --git`), чтобы lock-файлы
        // не съедали контекст модели.
        $sections = preg_split('/^(?=diff --git )/m', $diff) ?: [];
        $kept     = array_filter($sections, static function (string $s): bool {
            foreach (self::SKIP_FILES as $noise) {
                if (str_contains(explode("\n", $s)[0] ?? '', $noise)) {
                    return false;
                }
            }

            return true;
        });

        return implode('', $kept);
    }

    private function postComment(int $number, string $sha, string $verdict): void
    {
        $body = sprintf(
            "%s\n\n🤖 **Локальное ревью** (%s, ollama)\n\n%s",
            $this->marker($sha),
            $this->llmModelLabel(),
            $verdict,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'review') ?: null;
        if ($tmp === null) {
            return;
        }
        file_put_contents($tmp, $body);
        $this->gh(['pr', 'comment', (string) $number, '--body-file', $tmp]);
        @unlink($tmp);
    }

    private function marker(string $sha): string
    {
        return sprintf('<!-- local-review:%s -->', $sha);
    }

    private function llmModelLabel(): string
    {
        return $_ENV['LOCAL_LLM_MODEL'] ?? getenv('LOCAL_LLM_MODEL') ?: 'local';
    }

    /** Считаем СТРОКИ-блокеры, а не вхождения эмодзи: модель порой копирует легенду приоритетов. */
    private function blockers(string $verdict): int
    {
        $lines = preg_split('/\R/', $verdict) ?: [];

        return count(array_filter($lines, static fn (string $l): bool => str_starts_with(ltrim($l), '🔴')));
    }

    /** @param list<string> $args */
    private function gh(array $args): ?string
    {
        $bin     = is_executable(self::GH_BIN) ? self::GH_BIN : 'gh';
        $process = new Process([$bin, ...$args], $this->projectDir, timeout: 120);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private function acquireLock(): bool
    {
        $handle = fopen($this->projectDir . '/var/review_pr.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $handle;

        return true;
    }
}
