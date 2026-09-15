<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Service\Keyword\KeywordBlocklist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Зачистка уже собранного мусора в brand_keyword по минус-словам (KeywordBlocklist).
 *
 * Строки ПОМЕЧАЮТСЯ (blocked_at/blocked_reason), а не удаляются: правило проекта —
 * только soft-delete, плюс список минус-слов правится (KEYWORD_STOPWORDS), и по метке
 * видно, что именно он отсёк (ложное минус-слово → снять метку, а не собирать заново).
 *
 * meta_keywords пересобирается из чистых фраз: строка производная, её можно
 * восстановить, поэтому мусор из неё убираем сразу. Видимый текст (description/anons/
 * FAQ) НЕ трогаем — его регексом не почистить, нужна перегенерация; команда только
 * печатает список таких брендов.
 *
 *   php bin/console app:brand:keywords-purge --dry-run
 *   php bin/console app:brand:keywords-purge
 */
#[AsCommand(
    name: 'app:brand:keywords-purge',
    description: 'Пометить мусорные ключевики минус-словами и пересобрать meta_keywords',
)]
class PurgeKeywordJunkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KeywordBlocklist $blocklist,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, ничего не писать')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Размер батча', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batch  = max(50, (int) $input->getOption('batch'));

        $blockedRows = $this->markKeywords($io, $dryRun, $batch);
        $metaFixed   = $this->rebuildMetaKeywords($io, $dryRun);
        $this->reportContaminatedText($io);

        $io->success(sprintf(
            '%s: помечено фраз — %d, пересобрано meta_keywords — %d',
            $dryRun ? 'DRY-RUN' : 'Готово',
            $blockedRows,
            $metaFixed,
        ));

        return Command::SUCCESS;
    }

    /** Помечает мусорные фразы. Идемпотентна: уже помеченные уходят из выборки. */
    private function markKeywords(SymfonyStyle $io, bool $dryRun, int $batch): int
    {
        $repo    = $this->em->getRepository(BrandKeyword::class);
        $blocked = 0;
        $reasons = [];
        $offset  = 0;

        while (true) {
            /** @var BrandKeyword[] $rows */
            $rows = $repo->createQueryBuilder('k')
                ->where('k.blockedAt IS NULL')
                ->orderBy('k.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults($batch)
                ->getQuery()
                ->getResult();

            if ($rows === []) {
                break;
            }

            $markedInBatch = 0;
            foreach ($rows as $row) {
                $reason = $this->blocklist->match($row->getKeyword());
                if ($reason === null) {
                    continue;
                }
                $markedInBatch++;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                if (!$dryRun) {
                    $row->block($reason);
                }
            }
            $blocked += $markedInBatch;

            if (!$dryRun) {
                $this->em->flush();
            }

            // Помеченные уходят из выборки (blockedAt IS NULL) → окно двигаем только
            // на оставшиеся. В dry-run не пишем ничего, поэтому на всю страницу.
            $offset += count($rows) - ($dryRun ? 0 : $markedInBatch);
            $this->em->clear();
        }

        arsort($reasons);
        if ($reasons !== []) {
            $io->section('Сработавшие минус-слова');
            $io->table(
                ['минус-слово', 'фраз'],
                array_map(static fn ($k, $v) => [$k, $v], array_keys($reasons), array_values($reasons)),
            );
        }

        return $blocked;
    }

    /** Пересобирает meta_keywords из чистых фраз (строка производная — восстановима). */
    private function rebuildMetaKeywords(SymfonyStyle $io, bool $dryRun): int
    {
        /** @var Brand[] $brands */
        $brands = $this->em->getRepository(Brand::class)->createQueryBuilder('b')
            ->where('b.metaKeywords IS NOT NULL')
            ->andWhere("b.metaKeywords <> ''")
            ->getQuery()
            ->getResult();

        $fixed = 0;
        foreach ($brands as $brand) {
            $phrases = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $brand->getMetaKeywords()),
            ), static fn (string $p) => $p !== ''));

            $clean = $this->blocklist->filter($phrases);
            if (count($clean) === count($phrases)) {
                continue;
            }

            $fixed++;
            $io->text(sprintf(
                '  meta: %s — убрано %d из %d',
                $brand->getSlug(),
                count($phrases) - count($clean),
                count($phrases),
            ));

            if (!$dryRun) {
                $brand->setMetaKeywords($clean === [] ? null : mb_substr(implode(', ', $clean), 0, 255));
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }
        $this->em->clear();

        return $fixed;
    }

    /**
     * Видимый текст регексом не чистится — печатаем бренды под перегенерацию
     * (app:brand:generate-content --id=...) и не трогаем содержимое.
     */
    private function reportContaminatedText(SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT b.id, b.slug, b.status,
                    (b.description REGEXP :rx OR b.anons REGEXP :rx) AS in_text,
                    EXISTS (SELECT 1 FROM brand_faq f
                             WHERE f.brand_id = b.id AND (f.question REGEXP :rx OR f.answer REGEXP :rx)) AS in_faq
               FROM brand b
              WHERE b.description REGEXP :rx OR b.anons REGEXP :rx
                 OR EXISTS (SELECT 1 FROM brand_faq f
                             WHERE f.brand_id = b.id AND (f.question REGEXP :rx OR f.answer REGEXP :rx))
              ORDER BY (b.status = 'active') DESC, b.id",
            ['rx' => self::TEXT_REGEXP],
        );

        if ($rows === []) {
            return;
        }

        $active = array_filter($rows, static fn (array $r) => $r['status'] === 'active');
        $io->section(sprintf(
            'Мусор в видимом тексте: %d брендов (%d опубликованных) — нужна перегенерация',
            count($rows),
            count($active),
        ));
        $io->text('  ' . implode(' ', array_map(static fn (array $r) => (string) $r['id'], array_slice($rows, 0, 80))));
        $io->text('  php bin/console app:brand:generate-content --id=<ID> --force');
    }

    /**
     * Узкий REGEXP только для ОТЧЁТА по тексту (минус-слова живут в KeywordBlocklist).
     * Однозначные формы: 'nude' исключён (легитимный цвет, бренд Nudeshop),
     * 'слив' исключён (сливочный/слива).
     */
    private const TEXT_REGEXP = 'порно|porno|onlyfans|only fans|\\bporn\\b|xxx|нюдс|nudes';
}
