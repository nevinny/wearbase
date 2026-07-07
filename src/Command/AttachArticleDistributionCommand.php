<?php

namespace App\Command;

use App\Entity\ArticleDistribution;
use App\Repository\ArticleDistributionRepository;
use App\Repository\ArticleRepository;
use App\Service\Seo\ArticleMarkdownParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Привязка готовых копий статьи под площадку (var/seo/{platform}/*.md — другая
 * персона/тон, см. GenerateListicleCommand::PLATFORM_TONES) к статьям блога
 * (`article.source_file`), в `article_distribution` (версионируемо). Без привязки
 * фиды/выгрузки под площадку синтезировали бы копию из article.content — почти
 * дословный дубль блога на чужом (часто более сильном) домене.
 *
 * Сопоставление по topic-key: суффикс `-site(-pN)?.md` / `-{platform}(-pN)?.md`
 * отбрасывается — персона-индекс (pN) у site- и целевой версий генерится
 * независимо и НЕ совпадает (напр. listicle-…-site-p4.md ↔ listicle-…-dzen-p1.md).
 *
 * Каждый прогон с изменившимся текстом создаёт НОВУЮ версию (version+1, is_current
 * переезжает на неё) — история не теряется. Байт-в-байт совпадающий с уже текущей
 * версией файл — no-op. Файл, текст которого совпадает с article.content (нет
 * реальной персона-дифференциации) — пропускается с предупреждением, это и есть
 * риск дублей, которого мы избегаем.
 *
 *   php bin/console app:seo:attach-distribution dzen --dry-run
 *   php bin/console app:seo:attach-distribution dzen
 *   php bin/console app:seo:attach-distribution vc --dir=var/seo/vc
 */
#[AsCommand(
    name: 'app:seo:attach-distribution',
    description: 'Привязка var/seo/{platform}/*.md к статьям блога (article_distribution)',
)]
class AttachArticleDistributionCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articles,
        private readonly ArticleDistributionRepository $distributions,
        private readonly ArticleMarkdownParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('platform', InputArgument::REQUIRED, 'Код площадки (dzen, vc, pikabu, …)')
            ->addOption('dir',     null, InputOption::VALUE_REQUIRED, 'Папка с копиями (по умолчанию var/seo/{platform})')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать план без записи')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $platform = (string) $input->getArgument('platform');
        $dir      = rtrim((string) ($input->getOption('dir') ?: "var/seo/{$platform}"), '/');
        $dryRun   = (bool) $input->getOption('dry-run');

        $files = glob($dir . '/*.md') ?: [];
        if ($files === []) {
            $io->error("Нет .md в {$dir}.");
            return Command::FAILURE;
        }

        // topic-key => путь к файлу площадки
        $byKey = [];
        foreach ($files as $file) {
            $byKey[$this->topicKey(basename($file), $platform)] = $file;
        }

        $articles = $this->articles->createQueryBuilder('a')
            ->where('a.sourceFile IS NOT NULL')
            ->getQuery()
            ->getResult();

        $rows = [];
        $attached = $unchanged = $duplicates = $unmatchedArticles = 0;
        $usedKeys = [];
        foreach ($articles as $article) {
            $key = $this->topicKey((string) $article->getSourceFile(), 'site');
            if (!isset($byKey[$key])) {
                $unmatchedArticles++;
                continue;
            }
            $usedKeys[$key] = true;

            $file = $byKey[$key];
            $parsed = $this->parser->parse((string) file_get_contents($file));
            if ($parsed === null) {
                $io->warning('Пропущен (нет H1): ' . basename($file));
                continue;
            }
            [$title, $excerpt, $contentHtml] = $parsed;

            // Совпадающий по имени файл иногда оказывается тем же текстом, что и блог
            // (разные генерации/партии без реальной персона-дифференциации) — публиковать
            // такой «дубль» на внешней площадке смысла нет, это и есть риск, которого мы избегаем.
            if (trim($contentHtml) === trim($article->getContent())) {
                $io->warning(sprintf('Пропущен (текст идентичен блогу, нет персона-дифференциации): %s ↔ %s',
                    $article->getSourceFile(), basename($file)));
                $duplicates++;
                continue;
            }

            $current = $this->distributions->findCurrent($article, $platform);
            if ($current !== null && trim($current->getContent()) === trim($contentHtml)) {
                $unchanged++;
                continue;   // байт-в-байт та же версия — новую заводить незачем
            }

            $rows[] = [$article->getSlug(), basename($file), $current === null ? 1 : $current->getVersion() + 1];
            if ($dryRun) {
                continue;
            }

            if ($current !== null) {
                $current->setIsCurrent(false);
                $this->em->persist($current);
            }

            $distribution = (new ArticleDistribution())
                ->setArticle($article)
                ->setPlatform($platform)
                ->setVersion($this->distributions->nextVersion($article, $platform))
                ->setIsCurrent(true)
                ->setTitle($title)
                ->setExcerpt($excerpt)
                ->setContent($contentHtml)
                ->setSourceFile(basename($file));
            $this->em->persist($distribution);
            $attached++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $unmatchedFiles = array_diff_key($byKey, $usedKeys);

        $io->table(['slug', 'source_file', 'version'], $rows);
        $io->success(sprintf(
            'Площадка «%s» — новых версий: %d · без изменений: %d · дублей блога (пропущены): %d · статей без копии: %d · файлов без статьи: %d',
            $platform, $attached, $unchanged, $duplicates, $unmatchedArticles, count($unmatchedFiles),
        ));

        if ($unmatchedFiles !== []) {
            $io->note('Файлы без статьи-первоисточника (проверить slug/source_file): ' . implode(', ', array_map('basename', $unmatchedFiles)));
        }

        return Command::SUCCESS;
    }

    /** Базовое имя без суффикса `-{$tag}(-pN)?.md` — общий topic-key для site/площадка версий. */
    private function topicKey(string $filename, string $tag): string
    {
        return (string) preg_replace('/-' . preg_quote($tag, '/') . '(-p\d+)?\.md$/', '', $filename);
    }
}
