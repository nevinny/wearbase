<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Оркестратор боевого RAG-флоу: бесконечный цикл, каждая стадия запускается
 * ОТДЕЛЬНЫМ PHP-процессом (паттерн cron-демона). Ребёнок отработал → умер →
 * вся его память (Doctrine-профайлер, UoW, фрагментация) освобождена ОС.
 * Родитель только спавнит и стримит вывод — течь нечему.
 *
 * Маленькие лимиты на цикл: ребёнок живёт минуты, прогресс движется равномерно
 * по всем стадиям. keywords сюда НЕ входит (квота Wordstat 100/час — отдельный
 * долгоживущий процесс с собственным троттлингом).
 *
 *   php bin/console app:rag:daemon                          # все стадии, цикл раз в минуту
 *   php bin/console app:rag:daemon --once --stages=discover:5   # один цикл, для теста
 *
 *   # GPU не простаивает: два параллельных демона по типу ресурса (lock per набор стадий)
 *   php bin/console app:rag:daemon --stages=discover:30,fetch:250   # сеть/CPU
 *   php bin/console app:rag:daemon --stages=embed:30,generate:10    # GPU
 *
 * Времянка до Messenger-консьюмеров (см. tasktracker «Архитектура очереди»).
 */
#[AsCommand(
    name: 'app:rag:daemon',
    description: 'RAG: демон-оркестратор discover→fetch→embed→generate (стадии в дочерних процессах)',
)]
class RagDaemonCommand extends Command
{
    /** Стадии цикла: имя → [команда, [аргументы]]. Лимит можно переопределить в --stages=имя:N. */
    private const STAGES = [
        'discover' => ['app:brand:discover', ['30']],
        'crawl'    => ['app:brand:crawl',    ['30']],  // разворот own_site → own_page в очередь (между discover и fetch)
        'fetch'    => ['app:brand:fetch',    ['--max-urls=250']], // ломоть на цикл, дренаж продолжается между циклами
        'embed'    => ['app:brand:embed',    ['30']],
        'enrich'   => ['app:brand:enrich-contacts', ['10']], // извлечение магазинов/контактов из краула (перед generate/faq)
        'logo'     => ['app:brand:logo', ['20']],  // сетевой набор: лого из HTML own_site/маркетплейс (перекачка страницы)
        'generate' => ['app:brand:generate-content', ['10', '--grounded-only']], // без фактов не генерим: вода зацементировалась бы
        'faq'      => ['app:brand:faq', ['10']],   // GPU-набор: после generate (status=done)
        'extract'  => ['app:brand:extract', ['10']],  // GPU-набор: атрибуты из краула
        'push'     => ['app:brand:push', ['20']],  // сетевой набор: доставка готовых на прод
        'keywords' => ['app:brand:keywords', ['90']], // СВОЙ демон (один!): квота Wordstat 100/час общая, 90×37с ≈ 56 мин/цикл
    ];

    private const CHILD_TIMEOUT_SEC = 7200; // потолок на стадию; зависший ребёнок не блокирует демон навсегда

    /** @var resource|null держим открытым всё время жизни демона (flock) */
    private $lockHandle = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stages', null, InputOption::VALUE_REQUIRED,
                'Стадии цикла через запятую, опционально с лимитом: discover:30,crawl:10,fetch,embed:30,enrich:10,generate:10,faq:10,extract:10,push:20',
                'discover,crawl,fetch,embed,enrich,generate,faq,extract,push')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Пауза между циклами, сек', '60')
            ->addOption('once',  null, InputOption::VALUE_NONE,     'Один цикл и выход (для теста)')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Номер шарда (0..total-1) — прокидывается во все стадии', '0')
            ->addOption('total', null, InputOption::VALUE_REQUIRED, 'Всего шардов (>1 → можно крутить параллельные демоны того же набора)', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $sleep = max(1, (int) $input->getOption('sleep'));
        $once  = (bool) $input->getOption('once');
        $shard = max(0, (int) $input->getOption('shard'));
        $total = max(1, (int) $input->getOption('total'));

        $stages = $this->parseStages((string) $input->getOption('stages'), $io);
        if ($stages === null) {
            return Command::FAILURE;
        }

        // Lock per НАБОР стадий: можно крутить параллельно демон сетевых стадий
        // (discover,fetch) и демон GPU-стадий (embed,generate) — иначе GPU простаивает,
        // пока цикл занят сетью. Одинаковые наборы (независимо от лимитов) — коллизия.
        // ВАЖНО: наборы разных демонов не должны ПЕРЕСЕКАТЬСЯ (иначе двойная работа).
        $lockName = 'rag_daemon-' . implode('-', array_keys($stages));
        if ($total > 1) {
            $lockName .= "-s{$shard}of{$total}"; // шардированные демоны того же набора не коллизят
        }
        if (!$this->acquireLock($lockName)) {
            $io->error(sprintf('Демон уже запущен (var/%s.lock). Второй экземпляр не нужен.', $lockName));
            return Command::FAILURE;
        }

        $io->title('RAG · демон-оркестратор');
        $io->text(sprintf('Стадии: %s · пауза %dс · %s',
            implode(' → ', array_keys($stages)), $sleep, $once ? 'один цикл' : 'бесконечно'));

        $cycle = 0;
        while (true) {
            $cycle++;
            $io->section(sprintf('Цикл #%d · %s', $cycle, date('H:i:s')));

            foreach ($stages as $name => [$command, $args]) {
                $this->runStage($name, $command, $args, $io, $output, $shard, $total);
            }

            if ($once) {
                break;
            }
            sleep($sleep);
        }

        return Command::SUCCESS;
    }

    /**
     * "discover:5,embed" → ['discover' => ['app:brand:discover', ['5']], 'embed' => [...дефолт]].
     * Лимит применим только к стадиям с позиционным аргументом (у fetch его нет — игнорируем).
     *
     * @return array<string, array{0: string, 1: string[]}>|null null = ошибка парсинга
     */
    private function parseStages(string $spec, SymfonyStyle $io): ?array
    {
        // Без --stages — полный цикл по умолчанию: все стадии КРОМЕ keywords (у того
        // своя квота Wordstat 100/час и ~56 мин/цикл — отдельный демон, не блокирует общий).
        // Одной командой `app:rag:daemon` (напр. после ребута) поднимается весь конвейер.
        if (trim($spec) === '') {
            return array_diff_key(self::STAGES, ['keywords' => null]);
        }

        $stages = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $item) {
            [$name, $limit] = array_pad(explode(':', $item, 2), 2, null);
            if (!isset(self::STAGES[$name])) {
                $io->error(sprintf('Неизвестная стадия «%s». Доступны: %s', $name, implode(', ', array_keys(self::STAGES))));
                return null;
            }
            [$command, $args] = self::STAGES[$name];
            if ($limit !== null && $args !== []) {
                $n = (string) max(1, (int) $limit);
                // Позиционный лимит ('30') или опция с числом ('--max-urls=250') — меняем число.
                $args[0] = str_starts_with($args[0], '--')
                    ? (string) preg_replace('/\d+$/', $n, $args[0])
                    : $n;
            }
            $stages[$name] = [$command, $args];
        }

        return $stages === [] ? null : $stages;
    }

    private function runStage(string $name, string $command, array $args, SymfonyStyle $io, OutputInterface $output, int $shard = 0, int $total = 1): void
    {
        // Шардинг прокидываем во все стадии (все stage-команды поддерживают --shard/--total).
        $shardArgs = $total > 1 ? ['--shard=' . $shard, '--total=' . $total] : [];

        $io->text(sprintf('<comment>▶ %s</comment> (%s %s)', $name, $command, implode(' ', [...$args, ...$shardArgs])));

        // Свежий PHP-процесс на стадию: память ребёнка умирает вместе с ним.
        // --no-debug обязателен и тут — иначе dev-профайлер Doctrine копит SQL+backtrace.
        $process = new Process(
            [PHP_BINARY, '-d', 'memory_limit=512M', 'bin/console', $command, ...$args, ...$shardArgs, '--no-debug'],
            $this->projectDir,
            timeout: self::CHILD_TIMEOUT_SEC,
        );

        try {
            $process->run(static function (string $type, string $data) use ($output): void {
                $output->write($data);
            });
        } catch (ProcessTimedOutException) {
            $io->warning(sprintf('Стадия %s превысила %d сек — убита, демон продолжает.', $name, self::CHILD_TIMEOUT_SEC));
            return;
        }

        if (!$process->isSuccessful()) {
            // Не валим демон: следующий цикл доберёт (статус-машина resumable).
            $io->warning(sprintf('Стадия %s завершилась с кодом %d.', $name, (int) $process->getExitCode()));
        }
    }

    /** Эксклюзивный flock — защита от случайного второго демона с теми же стадиями. */
    private function acquireLock(string $name): bool
    {
        $path = $this->projectDir . '/var/' . $name . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $handle; // держим открытым — иначе GC снимет lock

        return true;
    }
}
