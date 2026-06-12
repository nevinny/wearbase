<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Публикует статьи блога из _docs/blog-drafts/*.html по встроенному манифесту.
 * Идемпотентна: существующий slug пропускается (с --force — контент обновляется).
 * Деплой: после выкатки кода выполнить на сервере
 *
 *   php bin/console app:blog:publish-drafts --dry-run
 *   php bin/console app:blog:publish-drafts
 */
#[AsCommand(name: 'app:blog:publish-drafts', description: 'Publish blog articles from _docs/blog-drafts into the article table')]
class PublishBlogDraftsCommand extends Command
{
    /** slug => [file, title, excerpt, publishedAt] — синхронизировано с локальной БД (канон) */
    private const MANIFEST = [
        'komissii-marketpleysov-2026' => [
            'file'        => 'komissii-marketpleysov-2026.html',
            'title'       => 'Комиссии маркетплейсов и налоги в 2026 году: сколько на самом деле платит продавец одежды',
            'excerpt'     => 'Wildberries — 34,5%, Ozon — до 52%, НДС 22%, порог УСН 20 млн, взносы 30%. Полный разбор расходов продавца одежды в 2026 году с примером расчёта: маркетплейс забирает 60–70% цены товара.',
            'publishedAt' => '2026-06-11 22:23:55',
        ],
        'kak-kupit-odezhdu-napryamuyu-ot-proizvoditelya' => [
            'file'        => 'kak-kupit-odezhdu-napryamuyu-ot-proizvoditelya.html',
            'title'       => 'Как купить одежду напрямую от производителя: гид для покупателя',
            'excerpt'     => 'Покупка напрямую у бренда: почему это дешевле маркетплейса, как найти производителя, проверить его за две минуты и что с доставкой и возвратом. Практический гид.',
            'publishedAt' => '2026-06-11 22:26:36',
        ],
        'pochemu-prodavtsy-uhodyat-s-wildberries' => [
            'file'        => 'pochemu-prodavtsy-uhodyat-s-wildberries.html',
            'title'       => 'Почему продавцы уходят с Wildberries в 2026 году — и куда',
            'excerpt'     => 'Активных селлеров стало меньше на 25%, доля продавцов с продажами на WB упала до 13,6%. Три причины исхода — комиссия 34,5%, обязательная реклама, налоги — и три направления, куда уходят бренды одежды.',
            'publishedAt' => '2026-06-11 22:36:36',
        ],
        'rossiyskie-brendy-odezhdy-gid' => [
            'file'        => 'rossiyskie-brendy-odezhdy-gid.html',
            'title'       => 'Российские бренды одежды: гид 2026 — как выбрать и где покупать напрямую',
            'excerpt'     => 'Какие направления развиты у российских марок — женская, мужская, спортивная одежда, стритвир; как отличить производителя от перекупщика и почему напрямую покупать выгоднее, чем на маркетплейсе.',
            'publishedAt' => '2026-06-11 22:46:36',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articles,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be published')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Update content of already existing articles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $draftsDir = $this->projectDir . '/_docs/blog-drafts';

        $created = $updated = $skipped = 0;

        foreach (self::MANIFEST as $slug => $meta) {
            $path = $draftsDir . '/' . $meta['file'];
            if (!is_file($path)) {
                $io->warning("Draft file missing, skipping: {$meta['file']}");
                continue;
            }

            $content = trim((string) file_get_contents($path));
            $existing = $this->articles->findOneBy(['slug' => $slug]);

            if ($existing && !$force) {
                $io->text("skip (exists): {$slug}");
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $io->text(($existing ? 'would update: ' : 'would create: ') . $slug . ' (' . mb_strlen($content) . ' chars)');
                continue;
            }

            $article = $existing ?? new Article();
            $article
                ->setSlug($slug)
                ->setTitle($meta['title'])
                ->setLocale('ru')
                ->setExcerpt($meta['excerpt'])
                ->setContent($content)
                ->setPublishedAt(new \DateTime($meta['publishedAt']));

            if (!$existing) {
                $this->em->persist($article);
                ++$created;
            } else {
                ++$updated;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success("Done: created {$created}, updated {$updated}, skipped {$skipped}.");

        return Command::SUCCESS;
    }
}
