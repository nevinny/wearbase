<?php

namespace App\Command;

use App\Service\YandexSearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Живая проверка выдачи Яндекса по запросу (Yandex Search API, ПЛАТНЫЙ ~0.49 ₽/запрос,
 * дневной кап YandexSearchMeter) — доказательная база позиций для скилла seo-audit.
 *
 * Статусы:
 *  - FOUND      — домен найден, позиция = место первого его URL;
 *  - NOT_IN_TOP — выдача пришла, домена в ней нет → «не в первых N»;
 *  - UNKNOWN    — пустой ответ: ключ не задан/невалиден, кап, 4xx, сеть. НЕ «не ранжируется».
 *
 * GROUP_MODE_FLAT (как в клиенте) может отдать один домен несколько раз подряд → позиция
 * занижена против реальной выдачи; рядом печатаем число РАЗНЫХ доменов выше — ближе к тому,
 * что видит пользователь. Регион — дефолт API (параметр региона клиент не передаёт).
 *
 *   php bin/console app:seo:serp "пошив одежды на заказ" --domain=example.ru --no-debug
 *   php bin/console app:seo:serp "ателье москва" --limit=30 --json --no-debug
 */
#[AsCommand(
    name: 'app:seo:serp',
    description: 'Yandex SERP по запросу (платный API): позиция домена FOUND / NOT_IN_TOP / UNKNOWN',
)]
class SeoSerpCommand extends Command
{
    public function __construct(
        private readonly YandexSearchClient $search,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Поисковый запрос')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Искать позицию домена (с поддоменами)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Глубина выдачи', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывод JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $query  = trim((string) $input->getArgument('query'));
        $limit  = max(1, min(100, (int) $input->getOption('limit')));
        $domain = $this->normalizeHost((string) $input->getOption('domain'));

        $docs = $this->search->isConfigured() ? $this->search->search($query, $limit) : [];

        $result = [
            'query'    => $query,
            'engine'   => 'yandex (Search API, регион по умолчанию)',
            'date'     => date('Y-m-d H:i'),
            'depth'    => $limit,
            'returned' => count($docs),
            'status'   => $docs === [] ? 'UNKNOWN' : null,
            'domain'   => $domain ?: null,
            'position' => null,
            'distinct_domains_above' => null,
            'matched_url' => null,
            'results'  => [],
        ];

        $hostsAbove = [];
        foreach ($docs as $i => $doc) {
            $host = $this->normalizeHost((string) parse_url($doc['url'], PHP_URL_HOST));
            $result['results'][] = ['pos' => $i + 1, 'host' => $host, 'url' => $doc['url'], 'title' => $doc['title']];
            if ($domain !== '' && $result['position'] === null) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    $result['position'] = $i + 1;
                    $result['distinct_domains_above'] = count($hostsAbove);
                    $result['matched_url'] = $doc['url'];
                } else {
                    $hostsAbove[$host] = true;
                }
            }
        }
        if ($docs !== []) {
            $result['status'] = $domain === '' ? 'OK' : ($result['position'] !== null ? 'FOUND' : 'NOT_IN_TOP');
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Яндекс «%s» · %s · вернулось %d из %d', $query, $result['date'], $result['returned'], $limit));
        if ($docs === []) {
            $io->warning('UNKNOWN: пустой ответ (ключ/кап/4xx/сеть). Это НЕ «не в выдаче».');
            return Command::SUCCESS;
        }
        $io->table(['#', 'хост', 'url'], array_map(fn (array $r) => [$r['pos'], $r['host'], $r['url']], $result['results']));
        if ($domain !== '') {
            $io->writeln(match ($result['status']) {
                'FOUND' => sprintf('FOUND: %s — позиция %d (разных доменов выше: %d) · %s', $domain, $result['position'], $result['distinct_domains_above'], $result['matched_url']),
                default => sprintf('NOT_IN_TOP: %s не в первых %d результатах', $domain, $result['returned']),
            });
        }

        return Command::SUCCESS;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
