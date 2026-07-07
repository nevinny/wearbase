<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\ArticleDistribution;
use App\Repository\ArticleDistributionRepository;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Привязка готовых копий статьи под площадку (var/seo/**\/*.md с суффиксом
 * -{platform}(-pN)?.md — другая персона/тон, см. GenerateListicleCommand::
 * PLATFORM_TONES) к статьям блога (article.source_file), в article_distribution
 * (версионируемо). Общий для `app:seo:attach-distribution` (ручной/крон прогон) и
 * `app:seo:publish-blog` (автопривязка сразу после публикации, если копии под
 * площадки уже лежат рядом на диске).
 *
 * Обнаружение — по СУФФИКСУ ИМЕНИ ФАЙЛА, не по имени папки: копии под одну и ту же
 * площадку на практике оказываются раскиданы по нескольким папкам генерации
 * (var/seo/dzen, var/seo/guides, …), а имя папки не всегда совпадает с площадкой
 * (var/seo/blog хранит `-site.md` — canonical-версии, не площадку "blog"-тон).
 */
class ArticleDistributionAttacher
{
    // Держать в синхроне с GenerateListicleCommand::PLATFORM_TONES / SeoGuideCommand::
    // PLATFORM_TONES минус 'site' — canonical-блог публикуется отдельно, app:seo:publish-blog.
    public const KNOWN_PLATFORMS = ['vc', 'dtf', 'pikabu', 'press', 'blog', 'dzen'];

    private const EXCLUDED_DIRS = ['_prev', '_prev2', '_prev3', '_prev_schema'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articles,
        private readonly ArticleDistributionRepository $distributions,
        private readonly ArticleMarkdownParser $parser,
    ) {
    }

    /**
     * Сканирует $baseDir рекурсивно и привязывает копии под ВСЕ найденные площадки.
     *
     * @return array<string, array> platform => summary (см. attachPlatform())
     */
    public function attachAll(string $baseDir = 'var/seo', bool $dryRun = false): array
    {
        $results = [];
        foreach ($this->discoverFiles($baseDir) as $platform => $files) {
            $results[$platform] = $this->attachPlatform($platform, $files, $dryRun);
        }

        return $results;
    }

    /**
     * Сканирует $baseDir рекурсивно, группируя .md-файлы по площадке (суффикс имени).
     *
     * @return array<string, string[]> platform => пути файлов
     */
    public function discoverFiles(string $baseDir): array
    {
        if (!is_dir($baseDir)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->name('*.md')
            ->in($baseDir)
            ->exclude(self::EXCLUDED_DIRS);

        $pattern = '/-(' . implode('|', array_map(
            static fn(string $p) => preg_quote($p, '/'),
            self::KNOWN_PLATFORMS,
        )) . ')(-p\d+)?\.md$/';

        $byPlatform = [];
        foreach ($finder as $file) {
            if (preg_match($pattern, $file->getFilename(), $m)) {
                $byPlatform[$m[1]][] = $file->getPathname();
            }
        }

        return $byPlatform;
    }

    /**
     * @param string[] $files
     * @return array{platform:string, rows:array, warnings:string[], attached:int, unchanged:int, duplicates:int, unmatchedArticles:int, unmatchedFiles:string[]}
     */
    public function attachPlatform(string $platform, array $files, bool $dryRun = false): array
    {
        // topic-key => путь к файлу площадки; при коллизии (тот же topic-key лежит в
        // двух папках генерации) побеждает файл со свежим mtime — не порядок сканирования.
        $byKey = [];
        foreach ($files as $file) {
            $key = $this->topicKey(basename($file), $platform);
            if (isset($byKey[$key]) && filemtime($byKey[$key]) >= filemtime($file)) {
                continue;
            }
            $byKey[$key] = $file;
        }

        $articles = $this->articles->createQueryBuilder('a')
            ->where('a.sourceFile IS NOT NULL')
            ->getQuery()
            ->getResult();

        $rows = [];
        $warnings = [];
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
                $warnings[] = 'Пропущен (нет H1): ' . basename($file);
                continue;
            }
            [$title, $excerpt, $contentHtml] = $parsed;

            // Совпадающий по имени файл иногда оказывается тем же текстом, что и блог
            // (разные генерации/партии без реальной персона-дифференциации) — публиковать
            // такой «дубль» на внешней площадке смысла нет, это и есть риск, которого мы избегаем.
            if (trim($contentHtml) === trim($article->getContent())) {
                $warnings[] = sprintf('Пропущен (текст идентичен блогу, нет персона-дифференциации): %s ↔ %s',
                    $article->getSourceFile(), basename($file));
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

        return [
            'platform' => $platform,
            'rows' => $rows,
            'warnings' => $warnings,
            'attached' => $attached,
            'unchanged' => $unchanged,
            'duplicates' => $duplicates,
            'unmatchedArticles' => $unmatchedArticles,
            'unmatchedFiles' => array_map('basename', array_values(array_diff_key($byKey, $usedKeys))),
        ];
    }

    /** Базовое имя без суффикса `-{$tag}(-pN)?.md` — общий topic-key для site/площадка версий. */
    public function topicKey(string $filename, string $tag): string
    {
        return (string) preg_replace('/-' . preg_quote($tag, '/') . '(-p\d+)?\.md$/', '', $filename);
    }
}
