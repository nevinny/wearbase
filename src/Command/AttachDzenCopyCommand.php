<?php

namespace App\Command;

use App\Repository\ArticleRepository;
use App\Service\Seo\ArticleMarkdownParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Привязка готовых Дзен-копий (var/seo/dzen/*.md, другая персона — не дубль блога)
 * к уже опубликованным статьям блога (`article.source_file`), заполняет
 * `dzen_content`/`dzen_source_file`. Без этой привязки /rss/dzen.xml отдавал бы
 * читателю почти дословный дубль блога — риск дублей на сильном домене Дзена.
 *
 * Сопоставление по topic-key: суффикс `-site(-pN)?.md` / `-dzen(-pN)?.md` отбрасывается,
 * персона-индекс (pN) у site- и dzen-версий генерится независимо и НЕ совпадает
 * (напр. listicle-…-site-p4.md ↔ listicle-…-dzen-p1.md) — сравнивать только базу имени.
 *
 * Если сопоставленный по имени файл на деле байт-в-байт совпадает с блоговым текстом
 * (найдено на живых данных: var/seo/blog-alice и var/seo/dzen держали один и тот же
 * hub-*.md без персона-дифференциации) — НЕ привязываем, такая «копия» на Дзене была бы
 * дублем блога, ровно тем риском, ради которого весь этот механизм существует.
 *
 * Идемпотентна (пропускает статьи с уже заполненным dzen_content, кроме --force).
 *
 *   php bin/console app:seo:attach-dzen-copy --dry-run
 *   php bin/console app:seo:attach-dzen-copy
 *   php bin/console app:seo:attach-dzen-copy --force
 */
#[AsCommand(
    name: 'app:seo:attach-dzen-copy',
    description: 'Привязка var/seo/dzen/*.md к статьям блога (dzen_content) для /rss/dzen.xml',
)]
class AttachDzenCopyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articles,
        private readonly ArticleMarkdownParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir',     null, InputOption::VALUE_REQUIRED, 'Папка с Дзен-копиями', 'var/seo/dzen')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать план без записи')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Перезаписать уже привязанные')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dir    = rtrim((string) $input->getOption('dir'), '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $files = glob($dir . '/*.md') ?: [];
        if ($files === []) {
            $io->error("Нет .md в {$dir}.");
            return Command::FAILURE;
        }

        // topic-key => путь к dzen-файлу
        $byKey = [];
        foreach ($files as $file) {
            $key = $this->topicKey(basename($file), 'dzen');
            $byKey[$key] = $file;
        }

        $articles = $this->articles->createQueryBuilder('a')
            ->where('a.sourceFile IS NOT NULL')
            ->getQuery()
            ->getResult();

        $rows = [];
        $attached = $skipped = $unmatchedArticles = $duplicates = 0;
        $usedKeys = [];
        foreach ($articles as $article) {
            $key = $this->topicKey((string) $article->getSourceFile(), 'site');
            if (!isset($byKey[$key])) {
                $unmatchedArticles++;
                continue;
            }
            $usedKeys[$key] = true;

            if ($article->getDzenContent() !== null && !$force) {
                $skipped++;
                continue;
            }

            $dzenFile = $byKey[$key];
            $md = (string) file_get_contents($dzenFile);
            $parsed = $this->parser->parse($md);
            if ($parsed === null) {
                $io->warning('Пропущен (нет H1): ' . basename($dzenFile));
                continue;
            }
            [, , $contentHtml] = $parsed;

            // Совпадающий по имени файл иногда оказывается тем же текстом, что и блог
            // (разные генерации/партии без реальной персона-дифференциации) — публиковать
            // такой «дубль» на Дзен смысла нет, это и есть риск, которого мы избегаем.
            if (trim($contentHtml) === trim((string) $article->getContent())) {
                $io->warning(sprintf('Пропущен (текст идентичен блогу, нет персона-дифференциации): %s ↔ %s',
                    $article->getSourceFile(), basename($dzenFile)));
                $duplicates++;
                continue;
            }

            $rows[] = [$article->getSlug(), basename($dzenFile)];
            if ($dryRun) {
                continue;
            }

            $article->setDzenContent($contentHtml)->setDzenSourceFile(basename($dzenFile));
            $this->em->persist($article);
            $attached++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $unmatchedFiles = array_diff_key($byKey, $usedKeys);

        $io->table(['slug', 'dzen source_file'], $rows);
        $io->success(sprintf('Привязано: %d · пропущено (уже привязаны): %d · дублей блога (не привязаны): %d · статей без Дзен-копии: %d · Дзен-файлов без статьи: %d',
            $attached, $skipped, $duplicates, $unmatchedArticles, count($unmatchedFiles)));

        if ($unmatchedFiles !== []) {
            $io->note("Дзен-файлы без статьи-первоисточника (проверить slug/source_file): " . implode(', ', array_map('basename', $unmatchedFiles)));
        }

        return Command::SUCCESS;
    }

    /** Базовое имя без суффикса `-{$tag}(-pN)?.md` — общий topic-key для site/dzen версий. */
    private function topicKey(string $filename, string $tag): string
    {
        return (string) preg_replace('/-' . preg_quote($tag, '/') . '(-p\d+)?\.md$/', '', $filename);
    }
}
