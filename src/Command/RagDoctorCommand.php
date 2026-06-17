<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Инвариант-чекер RAG-конвейера: сверяет независимые формулы очередей и ловит
 * порчу состояния (рассинхрон счётчиков, лоссовые демоуты, застрявший контент).
 * Регресс-сеть для рефакторинга + runtime-алерт (вешать в scheduled_command).
 *
 * Severity:
 *  - error: порча данных / инвариант домена нарушен → exit 1.
 *  - warn:  известное расхождение, которое закрывает Фаза 2 (не валит CI, но видно).
 *
 *   php bin/console app:rag:doctor            # отчёт + exit-код
 *   php bin/console app:rag:doctor --strict   # warn тоже валит (для CI после Фазы 2)
 */
#[AsCommand(
    name: 'app:rag:doctor',
    description: 'Инварианты RAG-конвейера: сверка очередей, ловля порчи состояния',
)]
class RagDoctorCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'warn тоже валит exit-код');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $strict = (bool) $input->getOption('strict');
        $io->title('RAG doctor — инварианты конвейера');

        $checks = [
            // [имя, severity, count нарушений, пояснение]
            $this->check(
                'done с пустым описанием/meta',
                'error',
                "SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 WHERE p.status='done' AND (b.description IS NULL OR b.description=''
                   OR b.meta_title IS NULL OR b.meta_title='' OR b.meta_description IS NULL OR b.meta_description='')",
                'done обязан иметь description+meta (predicate isPublishReady)',
            ),
            $this->check(
                'застрявший контент: embedded + generated_at + непустое описание',
                'error',
                "SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 WHERE p.status='embedded' AND p.generated_at IS NOT NULL AND b.description<>''",
                'демотнутый done — невидим generate, чинит app:rag:reconcile-stuck',
            ),
            $this->check(
                'статус inactive (невалидный backed-enum Statuses)',
                'error',
                "SELECT COUNT(*) FROM brand WHERE status='inactive'",
                'inactive нет в enum Statuses → падение гидрации; правильное скрытие = disabled',
            ),
            $this->check(
                'остаток ключевиков: отчёт ≢ админка',
                'error',
                // расхождение = бренды, которых считает одна формула и не считает другая
                "SELECT ABS(
                    (SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
                       WHERE b.status IN ('active','new') AND p.keywords_status IS NULL
                         AND NOT EXISTS (SELECT 1 FROM brand_keyword k WHERE k.brand_id=b.id))
                  - (SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id
                     LEFT JOIN brand_keyword k ON k.brand_id=b.id
                       WHERE b.status IN ('active','new') AND k.id IS NULL AND (p.id IS NULL OR p.keywords_status IS NULL))
                 )",
                'формулы PipelineReportCommand и RagDashboardController должны совпадать',
            ),
            $this->check(
                'очередь генерации: статус embedded ≢ что видит generate',
                'warn',
                // embedded-бренды, невидимые текущему generate (описание непустое) — §2①, чинит Фаза 2
                "SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 WHERE p.status='embedded' AND b.description IS NOT NULL AND b.description<>''",
                'generate ходит по findWithoutDescription (пустое описание), а очередь — по status=embedded → §2①, Фаза 2',
            ),
        ];

        $rows = [];
        $hardFail = false;
        $warnFail = false;
        foreach ($checks as $c) {
            $ok = $c['count'] === 0;
            $mark = $ok ? '✅' : ($c['severity'] === 'error' ? '❌' : '⚠️');
            if (!$ok && $c['severity'] === 'error') {
                $hardFail = true;
            }
            if (!$ok && $c['severity'] === 'warn') {
                $warnFail = true;
            }
            $rows[] = [$mark, $c['name'], $c['count'], $c['note']];
        }

        $io->table(['', 'Инвариант', 'Наруш.', 'Пояснение'], $rows);

        if ($hardFail) {
            $io->error('Нарушены hard-инварианты (error) — порча состояния.');
            return Command::FAILURE;
        }
        if ($warnFail && $strict) {
            $io->warning('Есть warn-расхождения, --strict → fail.');
            return Command::FAILURE;
        }
        if ($warnFail) {
            $io->warning('Есть warn-расхождения (ожидаемо до Фазы 2).');
            return Command::SUCCESS;
        }

        $io->success('Все инварианты зелёные.');
        return Command::SUCCESS;
    }

    /** @return array{name:string,severity:string,count:int,note:string} */
    private function check(string $name, string $severity, string $sql, string $note): array
    {
        return ['name' => $name, 'severity' => $severity, 'count' => (int) $this->db->fetchOne($sql), 'note' => $note];
    }
}
