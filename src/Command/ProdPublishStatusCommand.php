<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Единая точка правды о публикации брендов на проде. Каталог генерируется на Mac (dev),
 * а фактическую публикацию (`new` + `publish_pending=1` → `active`, `published_at`) делает
 * дрип-кроном `app:brand:publish-tick` НА ПРОДЕ (см. PublishTickCommand) — dev-БД и прод-БД
 * поэтому расходятся по статусу, и смотреть их нужно строго на проде, а не локально.
 *
 * Только Mac: дёргает agent-API прода `GET /api/v1/brands/published` (тот же
 * auth-паттерн, что PushBrandsCommand → /api/v1/brands/upsert: X-Agent-Token, без
 * подписи — GET) и печатает список брендов, которые дрип реально вывел за окно.
 * Ключ сопоставления с dev — slug (prod brand.id ≠ dev brand.id, свой autoincrement).
 */
#[AsCommand(
    name: 'app:prod:publish-status',
    description: 'Статус публикаций на проде (что вывел дрип publish-tick) — запуск только с Mac',
)]
class ProdPublishStatusCommand extends Command
{
    private const TIMEOUT_SEC = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $apiToken,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Окно в часах', 24)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Напечатать сырой JSON-ответ прода вместо таблицы')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = (int) $input->getOption('hours');
        $asJson = (bool) $input->getOption('json');

        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->apiToken) === '') {
            $io->error('Не заданы PROD_API_URL / AGENT_API_TOKEN в .env.local — команда только с Mac.');
            return Command::FAILURE;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                rtrim($this->prodApiUrl, '/') . '/api/v1/brands/published?hours=' . $hours,
                [
                    'headers' => ['X-Agent-Token' => $this->apiToken],
                    'timeout' => self::TIMEOUT_SEC,
                ],
            );
        } catch (\Throwable $e) {
            $io->error('Запрос к проду не прошёл: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($response->getStatusCode() !== 200) {
            $io->error(sprintf('Прод ответил HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 500)));
            return Command::FAILURE;
        }

        $data = $response->toArray(false);

        if ($asJson) {
            $output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $io->title(sprintf('Опубликовано за последние %d ч: %d', $data['hours'] ?? $hours, count($items)));

        if ($items === []) {
            $io->note('За окно ничего не опубликовано.');
            return Command::SUCCESS;
        }

        // prod_id намеренно не выводим в таблицу — не ключ (dev/prod id расходятся),
        // сопоставление с dev делается по slug. Остаётся в сыром --json для аудита.
        $io->table(
            ['Slug', 'Название', 'Опубликован', 'URL'],
            array_map(
                static fn (array $r) => [$r['slug'] ?? '—', $r['title'] ?? '—', $r['published_at'] ?? '—', $r['url'] ?? '—'],
                $items,
            ),
        );

        return Command::SUCCESS;
    }
}
