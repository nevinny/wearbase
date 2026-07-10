<?php

namespace App\Command;

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
 * Переиздание СУЩЕСТВУЮЩЕЙ блог-статьи (приём Т—Ж, «вечнозелёный URL»): тот же
 * slug, обновляются контент/title/meta_title (год) и updatedAt (→ dateModified в
 * JSON-LD, lastmod в sitemap, «обновлено» в байлайне). publishedAt (первая
 * публикация), статус и автор НЕ трогаются, новый slug НЕ создаётся.
 *
 * Это осознанный механизм ежегодного обновления той же темы — в отличие от
 * `app:seo:publish-blog --force`, который остаётся под запретом для готовых
 * статей (см. память «no-force-overwrite-ready-articles»). Новый повод/тема —
 * по-прежнему новый slug через publish-blog.
 *
 *   php bin/console app:seo:republish-blog top-5-brendov-streetwear-reyting --dry-run
 *   php bin/console app:seo:republish-blog top-5-brendov-streetwear-reyting
 *   php bin/console app:seo:republish-blog <slug> --file=var/seo/blog/listicle-...-site.md
 */
#[AsCommand(
    name: 'app:seo:republish-blog',
    description: 'Переиздание существующей статьи: тот же slug, свежий контент/год в meta_title, updatedAt=now',
)]
class SeoRepublishBlogCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository      $articles,
        private readonly ArticleMarkdownParser  $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug существующей статьи блога')
            ->addOption('file',    null, InputOption::VALUE_REQUIRED, 'Путь к свежему .md (по умолчанию var/seo/blog/{sourceFile} статьи)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать, что изменится, без записи')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $slug   = (string) $input->getArgument('slug');
        $dryRun = (bool) $input->getOption('dry-run');

        $article = $this->articles->findOneBy(['slug' => $slug]);
        if ($article === null) {
            $io->error("Статья со slug «{$slug}» не найдена. Для НОВОЙ статьи используйте app:seo:publish-blog.");
            return Command::FAILURE;
        }

        $file = (string) ($input->getOption('file') ?? ('var/seo/blog/' . $article->getSourceFile()));
        if ($article->getSourceFile() === null && !$input->getOption('file')) {
            $io->error('У статьи нет sourceFile — укажите свежий .md явно через --file.');
            return Command::FAILURE;
        }
        if (!is_file($file)) {
            $io->error("Файл не найден: {$file}");
            return Command::FAILURE;
        }

        $parsed = $this->parser->parse((string) file_get_contents($file));
        if ($parsed === null) {
            $io->error("В {$file} нет H1 — это не статья.");
            return Command::FAILURE;
        }
        [$title, $excerpt, $contentHtml, $metaTitle] = $parsed;

        $io->title('Переиздание статьи (тот же slug)');
        $io->definitionList(
            ['slug'            => $slug],
            ['publishedAt'     => $article->getPublishedAt()?->format('Y-m-d H:i') ?? '—'],
            ['title'           => sprintf('%s → %s', $article->getTitle(), $title)],
            ['meta_title'      => sprintf('%s → %s', $article->getMetaTitle() ?? '—', $metaTitle ?? '(без изменений)')],
            ['файл'            => $file],
        );

        if ($dryRun) {
            $io->note('DRY-RUN: ничего не записано.');
            return Command::SUCCESS;
        }

        $article->setTitle($title)->setExcerpt($excerpt)->setContent($contentHtml)->setSourceFile(basename($file));
        if ($metaTitle !== null) {
            $article->setMetaTitle($metaTitle);   // свежий год из даты генерации .md
        }
        // Created-трейт бампает updatedAt только на PrePersist — при переиздании ставим явно:
        // это и есть dateModified (JSON-LD), lastmod (sitemap) и «обновлено» (байлайн).
        $article->setUpdatedAt(new \DateTime());
        $this->em->flush();

        $io->success(sprintf('Переиздано: slug и publishedAt сохранены, updatedAt = %s.', $article->getUpdatedAt()->format('Y-m-d H:i')));
        $io->text('Копии под площадки (Дзен и др.) при необходимости обновить: app:seo:attach-distribution.');

        return Command::SUCCESS;
    }
}
