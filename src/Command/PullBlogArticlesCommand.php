<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pull статей блога прод→Mac (агент-API /api/v1/blog-articles): оживляет closed-loop
 * «статья проиндексирована → уведомление "публикуй в Дзен"» (SyncGscCommand::checkBlogIndex()),
 * который читает ЛОКАЛЬНУЮ таблицу article — а публикует статьи `app:seo:publish-blog` НА ПРОДЕ.
 * Без синка локальная article стейл и checkBlogIndex() не находит ни одной строки.
 *
 * Upsert по slug (глобально unique в article). НЕ трогает indexed_at/indexed_notified_at —
 * это локальные отметки антиповтора, прод их не ведёт и перетирать их синком нельзя (иначе
 * цикл начнёт спамить повторными уведомлениями). Ничего не удаляет (soft-delete-политика проекта).
 *
 * FAIL-OPEN: без PROD_API_URL или прод недоступен/эндпоинт не задеплоен (404) — warning, exit 0.
 *
 *   php bin/console app:blog:pull-articles --dry-run
 *   php bin/console app:blog:pull-articles
 */
#[AsCommand(name: 'app:blog:pull-articles', description: 'Синк статей блога прод→Mac для closed-loop «индексация→Дзен»')]
class PullBlogArticlesCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что будет вставлено/обновлено, без записи в БД');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (trim((string) $this->prodApiUrl) === '') {
            $io->warning('PROD_API_URL не задан — пропуск (fail-open).');
            return Command::SUCCESS;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                rtrim((string) $this->prodApiUrl, '/') . '/api/v1/blog-articles',
                ['headers' => ['X-Agent-Token' => (string) $this->agentToken], 'timeout' => 10],
            );
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $io->warning("Прод вернул HTTP {$status} на /api/v1/blog-articles (эндпоинт не задеплоен или недоступен) — пропуск.");
                return Command::SUCCESS;
            }
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $io->warning('Прод недоступен: ' . $e->getMessage() . ' — пропуск.');
            return Command::SUCCESS;
        }

        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            $io->warning('Неожиданный формат ответа прода — пропуск.');
            return Command::SUCCESS;
        }

        $existingSlugs = array_flip($this->db->fetchFirstColumn('SELECT slug FROM article'));

        $inserted = $updated = $unchanged = $skipped = 0;
        foreach ($items as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '' || trim((string) ($row['title'] ?? '')) === '') {
                $skipped++;
                continue;
            }

            $isNew = !isset($existingSlugs[$slug]);

            if ($dryRun) {
                $isNew ? $inserted++ : $updated++;
                continue;
            }

            $affected = $this->db->executeStatement(
                'INSERT INTO article (title, slug, locale, content, published_at, source_file, status, created_at, updated_at)
                 VALUES (:title, :slug, :locale, \'\', :published_at, :source_file, :status, COALESCE(:created_at, NOW()), NOW())
                 ON DUPLICATE KEY UPDATE
                     title = :title, locale = :locale, published_at = :published_at,
                     source_file = :source_file, status = :status, updated_at = NOW()',
                [
                    'title'        => mb_substr((string) $row['title'], 0, 255),
                    'slug'         => $slug,
                    'locale'       => (string) ($row['locale'] ?? 'ru'),
                    'published_at' => $row['published_at'] ?? null,
                    'source_file'  => $row['source_file'] ?? null,
                    'status'       => (string) ($row['status'] ?? 'active'),
                    'created_at'   => $row['created_at'] ?? null,
                ],
            );

            // MySQL ON DUPLICATE KEY UPDATE: 1 = insert, 2 = update-с-изменением, 0 = update-без изменений.
            match ($affected) {
                1 => $inserted++,
                2 => $updated++,
                default => $unchanged++,
            };
        }

        $io->text(sprintf(
            '%s: вставлено %d · обновлено %d · без изменений %d · пропущено %d (из %d в ответе)',
            $dryRun ? 'Dry-run' : 'Синк',
            $inserted, $updated, $unchanged, $skipped, count($items),
        ));

        return Command::SUCCESS;
    }
}
