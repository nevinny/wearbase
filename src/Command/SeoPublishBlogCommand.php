<?php

namespace App\Command;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Service\Seo\ArticleDistributionAttacher;
use App\Service\Seo\ArticleMarkdownParser;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Публикация сгенерированных блог-статей (var/seo/blog/*.md) в таблицу `article`
 * как canonical-первоисточники. Конвертирует Markdown → HTML (контент article
 * рендерится |raw, markdown-либы в проекте нет). Дрип-расписание через будущий
 * `publishedAt` (видимость блога = status=active AND publishedAt<=now) — статьи
 * выкладываются ровным темпом без ручной работы. Идемпотентна по slug.
 *
 * После публикации сразу привязывает готовые копии под площадки (var/seo/**,
 * ArticleDistributionAttacher — общая логика с app:seo:attach-distribution),
 * если они уже лежат на диске — `--no-attach-distribution` отключает.
 *
 * Стратегия и частота — docs/seo_publishing_strategy.md.
 *
 *   php bin/console app:seo:publish-blog --dry-run
 *   php bin/console app:seo:publish-blog --per-day=2 --start=2026-06-26
 *   php bin/console app:seo:publish-blog --force         # перезаписать контент существующих
 */
#[AsCommand(
    name: 'app:seo:publish-blog',
    description: 'Публикация var/seo/blog/*.md в блог (Article, MD→HTML, дрип через publishedAt)',
)]
class SeoPublishBlogCommand extends Command
{
    /** Слоты времени в течение дня для дрипа (равномерно). */
    private const SLOTS = ['09:00', '13:00', '17:00'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository      $articles,
        private readonly \App\Repository\AuthorRepository $authorsRepo,
        private readonly SluggerInterface       $slugger,
        private readonly \App\Service\LlmService $llm,
        private readonly ArticleMarkdownParser  $parser,
        private readonly ArticleDistributionAttacher $attacher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir',     null, InputOption::VALUE_REQUIRED, 'Папка с .md', 'var/seo/blog')
            ->addOption('per-day', null, InputOption::VALUE_REQUIRED, 'Сколько публиковать в день (дрип)', '2')
            ->addOption('start',   null, InputOption::VALUE_REQUIRED, 'Дата первой публикации YYYY-MM-DD (по умолчанию завтра)')
            ->addOption('locale',  null, InputOption::VALUE_REQUIRED, 'Локаль', 'ru')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать план без записи')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Перезаписать контент существующих slug')
            ->addOption('no-judge', null, InputOption::VALUE_NONE,    'Без LLM-судьи (по умолчанию судья включён)')
            ->addOption('author',  null, InputOption::VALUE_REQUIRED, 'Slug автора (байлайн + Person schema)', 'anna-semyannikova')
            ->addOption('no-attach-distribution', null, InputOption::VALUE_NONE, 'Не привязывать копии под площадки (var/seo/**) после публикации')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dir     = rtrim((string) $input->getOption('dir'), '/');
        $perDay  = max(1, (int) $input->getOption('per-day'));
        $locale  = (string) $input->getOption('locale');
        $dryRun  = (bool) $input->getOption('dry-run');
        $force   = (bool) $input->getOption('force');
        $judge   = !$input->getOption('no-judge');
        $author  = $this->authorsRepo->findOneActiveBySlug((string) $input->getOption('author'));
        if (!$author) {
            $io->warning(sprintf('Автор «%s» не найден — статьи будут без байлайна.', $input->getOption('author')));
        }

        $files = glob($dir . '/*.md') ?: [];
        if ($files === []) {
            $io->error("Нет .md в {$dir}.");
            return Command::FAILURE;
        }
        usort($files, fn($a, $b) => $this->priority($a) <=> $this->priority($b) ?: strcmp($a, $b));

        try {
            $start = $input->getOption('start')
                ? new \DateTime($input->getOption('start') . ' 00:00:00')
                : (new \DateTime('tomorrow'));
        } catch (\Exception) {
            $io->error('Неверная дата --start (нужен YYYY-MM-DD).');
            return Command::FAILURE;
        }

        $io->title('Публикация блог-статей (дрип)');
        $io->text(sprintf('Файлов: %d · темп: %d/день · старт: %s · локаль: %s%s',
            count($files), $perDay, $start->format('Y-m-d'), $locale, $dryRun ? ' · DRY-RUN' : ''));

        $rows = [];
        $created = $updated = $skipped = $drafted = 0;
        foreach (array_values($files) as $i => $file) {
            $md = (string) file_get_contents($file);
            $parsed = $this->parser->parse($md);
            if ($parsed === null) {
                $io->warning('Пропущен (нет H1): ' . basename($file));
                $skipped++;
                continue;
            }
            [$title, $excerpt, $contentHtml] = $parsed;

            $publishAt = (clone $start)
                ->modify('+' . intdiv($i, $perDay) . ' days')
                ->modify(self::SLOTS[$i % $perDay % count(self::SLOTS)]);

            $existing = $this->articles->findOneBy(['slug' => $this->slugFor($title, $locale)]);
            $slug = $existing ? $existing->getSlug() : $this->slugFor($title, $locale);

            $rows[] = [$publishAt->format('Y-m-d H:i'), $slug, mb_substr($title, 0, 60)];

            if ($dryRun) {
                continue;
            }

            if ($existing && !$force) {
                $skipped++;
                continue;
            }

            // LLM-судья перед live: не прошёл → новый идёт в ЧЕРНОВИК (status disabled, без
            // publishedAt), не в дрип. У существующих статус/дату не трогаем (только контент).
            $draft = false;
            if ($judge && !$existing) {
                $v = $this->llm->judgeArticle($title, $contentHtml);
                if ($v['fabrication'] || !$v['publishable']) {
                    $draft = true;
                    $io->text(sprintf('  ⚠ %s → черновик (score %d): %s', $slug, $v['score'],
                        $v['issues'] !== [] ? implode('; ', $v['issues']) : 'судья отклонил'));
                }
            }

            $article = $existing ?? (new Article())->setSlug($slug)->setLocale($locale);
            $article->setTitle($title)->setExcerpt($excerpt)->setContent($contentHtml)->setSourceFile(basename($file));
            if ($author && $article->getAuthor() === null) {
                $article->setAuthor($author);   // только если автор не задан (не затираем ручной выбор в EA)
            }
            if (!$existing) {
                $article->setStatus($draft ? Statuses::Disabled : Statuses::Active);
                $article->setPublishedAt($draft ? null : $publishAt);   // дату/статус ставим только новым
            }
            $this->em->persist($article);
            if ($draft) {
                $drafted++;
            } else {
                $existing ? $updated++ : $created++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(['publishedAt', 'slug', 'title'], $rows);
        $io->success(sprintf('Создано (live-дрип): %d · в черновик судьёй: %d · обновлено: %d · пропущено: %d',
            $created, $drafted, $updated, $skipped));

        // Копии под площадки (var/seo/dzen, var/seo/guides, …) часто уже лежат на диске
        // к моменту публикации блога — привязываем их сразу, чтобы не ждать отдельного
        // ручного/крон-прогона app:seo:attach-distribution.
        if (!$dryRun && !$input->getOption('no-attach-distribution')) {
            foreach ($this->attacher->attachAll('var/seo') as $platform => $result) {
                if ($result['attached'] === 0) {
                    continue;   // не шуметь, если для площадки ничего нового
                }
                $io->text(sprintf('  → «%s»: новых версий копий %d (%s)', $platform, $result['attached'],
                    implode(', ', array_column($result['rows'], 0))));
            }
        }

        return Command::SUCCESS;
    }

    /** Приоритет выкладки: листиклы и гео-гиды (коммерч./локальный интент) раньше нишевых. */
    private function priority(string $path): int
    {
        $b = basename($path);
        if (str_starts_with($b, 'listicle-')) {
            return 0;
        }
        if (str_contains($b, '-moskva-') || str_contains($b, '-sankt-')) {
            return 1;   // гео-гид
        }
        return 2;       // нишевый гид
    }

    private function slugFor(string $title, string $locale): string
    {
        $base = mb_strtolower((string) $this->slugger->slug($title, '-', $locale), 'UTF-8');
        $base = trim(preg_replace('/[^a-z0-9-]+/', '-', $base), '-') ?: 'article';
        $slug = $base;
        $n = 2;
        while (($a = $this->articles->findOneBy(['slug' => $slug])) && $a->getTitle() !== $title) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
