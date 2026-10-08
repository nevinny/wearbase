<?php

namespace App\Command;

use App\Service\Keyword\WordstatAuthException;
use App\Service\Keyword\WordstatClient;
use App\Service\Keyword\WordstatQuotaException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Разовый замер спроса по фразе (Wordstat topRequests, вся Россия) — для скилла seo-audit
 * и ручной разведки. Ничего не пишет в БД.
 *
 * ⚠️ Квота 100 запросов/час ОБЩАЯ с app:brand:keywords. Пустой ответ = НЕИЗВЕСТНО
 * (клиент глотает сетевые ошибки), а не «спроса нет».
 *
 *   php bin/console app:seo:wordstat "пошив одежды на заказ" --limit=30 --no-debug
 *   php bin/console app:seo:wordstat "ателье" --json --no-debug
 */
#[AsCommand(
    name: 'app:seo:wordstat',
    description: 'Wordstat: частотность фразы + включающие/похожие запросы (Россия), без записи в БД',
)]
class SeoWordstatCommand extends Command
{
    public function __construct(
        private readonly WordstatClient $wordstat,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('phrase', InputArgument::REQUIRED, 'Сид-фраза')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько фраз вернуть', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывод JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $phrase = trim((string) $input->getArgument('phrase'));

        if (!$this->wordstat->isConfigured()) {
            $io->error('WORDSTAT_API_KEY не задан');
            return Command::FAILURE;
        }

        try {
            $rows = $this->wordstat->keywordsFor($phrase, max(1, (int) $input->getOption('limit')));
        } catch (WordstatQuotaException | WordstatAuthException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $result = [
            'phrase'  => $phrase,
            'region'  => 'Россия (225)',
            'date'    => date('Y-m-d'),
            'status'  => $rows === [] ? 'UNKNOWN' : 'OK',
            'rows'    => $rows,
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if ($rows === []) {
            $io->warning('Пустой ответ — UNKNOWN (сбой запроса или нет данных), не «спроса нет».');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Wordstat «%s» · Россия · %s', $phrase, $result['date']));
        $io->table(
            ['фраза', 'тип', 'показов/мес'],
            array_map(fn (array $r) => [$r['keyword'], $r['type'], $r['monthlyShows'] ?? '—'], $rows),
        );

        return Command::SUCCESS;
    }
}
