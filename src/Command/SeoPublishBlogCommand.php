<?php

namespace App\Command;

use App\Entity\Article;
use App\Repository\ArticleRepository;
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
        private readonly SluggerInterface       $slugger,
        private readonly \App\Service\LlmService $llm,
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
            $parsed = $this->parse($md);
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

    /**
     * Разбор md: H1→title, блок «## Коротко»→excerpt, остальное (без H1) → HTML.
     * @return array{0:string,1:?string,2:string}|null
     */
    private function parse(string $md): ?array
    {
        $md = preg_replace('/<!--.*?-->/s', '', $md);            // убрать комментарии
        if (!preg_match('/^\#\s+(.+?)\s*$/m', $md, $m)) {
            return null;                                          // нет H1 — не статья
        }
        $title = trim($m[1]);
        $body  = preg_replace('/^\#\s+.+?\s*$/m', '', $md, 1);   // вырезать H1 из тела

        // excerpt из блока «## Коротко» (answer-nugget) — первый абзац, без разметки.
        $excerpt = null;
        if (preg_match('/^##\s+(?:Корот|Крат)\p{L}*\s*(.+?)(?=\n##\s|\z)/smui', $body, $lm)) {
            $plain = trim(preg_replace('/\s+/u', ' ', $this->stripInline($lm[1])));
            $excerpt = mb_substr($plain, 0, 300);
        }

        return [$title, $excerpt, $this->mdToHtml($body)];
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

    // ----------------------------------------------------------------- MD→HTML

    /** Минимальный конвертер под наши конструкции (заголовки/абзацы/таблица/списки/hr/JSON-LD). */
    private function mdToHtml(string $md): string
    {
        // JSON-LD <script>…</script> вынимаем как есть (это уже HTML), вернём в конце.
        $scripts = [];
        $md = preg_replace_callback('/<script type="application\/ld\+json">.*?<\/script>/s', function ($m) use (&$scripts) {
            $scripts[] = $m[0];
            return "\x00SCRIPT" . (count($scripts) - 1) . "\x00";
        }, $md);

        $lines = preg_split('/\r?\n/', (string) $md);
        $html = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = rtrim($lines[$i]);

            if (trim($line) === '') { $i++; continue; }

            // JSON-LD плейсхолдер
            if (preg_match('/^\x00SCRIPT(\d+)\x00$/', trim($line), $sm)) {
                $html[] = $scripts[(int) $sm[1]];
                $i++;
                continue;
            }
            // hr
            if (preg_match('/^(\*\*\*|---|___)\s*$/', $line)) { $html[] = '<hr>'; $i++; continue; }
            // заголовки
            if (preg_match('/^(##|###)\s+(.+)$/', $line, $hm)) {
                $tag = $hm[1] === '##' ? 'h2' : 'h3';
                $html[] = "<{$tag}>" . $this->inline($hm[2]) . "</{$tag}>";
                $i++;
                continue;
            }
            // таблица: блок строк, начинающихся с |
            if (str_starts_with(ltrim($line), '|')) {
                $tbl = [];
                while ($i < $n && str_starts_with(ltrim($lines[$i]), '|')) { $tbl[] = trim($lines[$i]); $i++; }
                $html[] = $this->table($tbl);
                continue;
            }
            // список
            if (preg_match('/^[-*]\s+/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^[-*]\s+(.+)$/', rtrim($lines[$i]), $im)) { $items[] = '<li>' . $this->inline($im[1]) . '</li>'; $i++; }
                $html[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }
            // абзац: до пустой строки
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,3}\s|[-*]\s|\||\*\*\*|---|___|\x00SCRIPT)/', ltrim($lines[$i]))) {
                $para[] = trim($lines[$i]); $i++;
            }
            if ($para !== []) {
                $html[] = '<p>' . $this->inline(implode(' ', $para)) . '</p>';
            }
        }

        return implode("\n", $html);
    }

    /** @param string[] $rows строки markdown-таблицы (включая разделитель). */
    private function table(array $rows): string
    {
        $cells = static fn(string $r): array => array_map('trim', explode('|', trim($r, "| \t")));
        $isSep = static fn(string $r): bool => (bool) preg_match('/^\|?[\s:|-]+\|?$/', $r) && str_contains($r, '-');

        $head = null;
        $body = [];
        foreach ($rows as $idx => $r) {
            if ($idx === 0) { $head = $cells($r); continue; }
            if ($idx === 1 && $isSep($r)) { continue; }     // строка-разделитель
            $body[] = $cells($r);
        }

        $out = '<table>';
        if ($head !== null) {
            $out .= '<thead><tr>' . implode('', array_map(fn($c) => '<th>' . $this->inline($c) . '</th>', $head)) . '</tr></thead>';
        }
        $out .= '<tbody>';
        foreach ($body as $row) {
            $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . $this->inline($c) . '</td>', $row)) . '</tr>';
        }

        return $out . '</tbody></table>';
    }

    /** Инлайн: экранирование + **жирный** + [текст](url). */
    private function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // ссылки (url у нас без & и кавычек — внутренние)
        $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            static fn($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);

        return $s;
    }

    /** Снять markdown-разметку (для excerpt). */
    private function stripInline(string $s): string
    {
        $s = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $s);   // ссылки → текст
        $s = preg_replace('/\*\*(.+?)\*\*/u', '$1', $s);          // жирный

        return (string) $s;
    }
}
