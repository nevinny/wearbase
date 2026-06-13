<?php

namespace App\Command;

use App\Entity\Brand;
use App\Service\SeoMetaService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ремонт SEO-meta активных брендов (docs/seo_adoption_plan.md п.5; доктрина пакета
 * _seo «оптимизировать позиции 5–20 / latent wins» + «_fit вместо реджекта»).
 *
 * Чинит ТОЛЬКО дефектные поля (хирургично, не переписывает валидную meta):
 *   - пустой meta_title / meta_description → собирает из названия (+город)/описания;
 *   - meta_title > 60 / meta_description > 155 → тримит по границе слова.
 *
 * Приоритет — по показам GSC (gsc_page_stats): бренды, которые уже ранжируются и
 * собирают impressions, чинятся первыми (рычаг CTR). Без GSC-данных — по id.
 *
 *   php bin/console app:seo:meta-repair --dry-run        # показать что починит
 *   php bin/console app:seo:meta-repair --limit=100      # починить 100
 *
 * ⚠️ Где запускать: на Mac/.43 (там GSC-данные для приоритета и канонические brand-
 * данные RAG-конвейера). Чтобы починка доехала на прод — ре-пуш бренда (content_version).
 */
#[AsCommand(
    name: 'app:seo:meta-repair',
    description: 'Ремонт дефектной SEO-meta брендов (пустая/длинная), приоритет по показам GSC',
)]
class MetaRepairCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly SeoMetaService $seoMeta,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько брендов починить', '50')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать правки без сохранения')
            ->addOption('min-impressions', null, InputOption::VALUE_REQUIRED, 'Только бренды с показами GSC ≥ N', '0')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $limit     = max(1, (int) $input->getOption('limit'));
        $dryRun    = (bool) $input->getOption('dry-run');
        $minImpr   = max(0, (int) $input->getOption('min-impressions'));

        $io->title('Ремонт SEO-meta брендов' . ($dryRun ? ' (dry-run)' : ''));

        $maxT = SeoMetaService::MAX_TITLE;
        $maxD = SeoMetaService::MAX_DESCRIPTION;

        // Дефектные active-бренды + показы GSC (LEFT JOIN — без GSC попадают с imp=0).
        // gsc_page_stats может не существовать (прод) → деградируем к выборке без приоритета.
        $defectWhere =
            "b.status = 'active' AND (
                b.meta_title IS NULL OR b.meta_title = '' OR CHAR_LENGTH(b.meta_title) > {$maxT}
                OR b.meta_description IS NULL OR b.meta_description = '' OR CHAR_LENGTH(b.meta_description) > {$maxD}
            )";

        try {
            $ids = $this->db->fetchFirstColumn(
                "SELECT b.id FROM brand b
                 LEFT JOIN (
                     SELECT SUBSTRING_INDEX(page_url, '/brands/', -1) AS slug, SUM(impressions) imp
                     FROM gsc_page_stats WHERE query IS NULL GROUP BY slug
                 ) g ON g.slug = b.slug
                 WHERE {$defectWhere} AND COALESCE(g.imp, 0) >= :minImpr
                 ORDER BY COALESCE(g.imp, 0) DESC, b.id
                 LIMIT {$limit}",
                ['minImpr' => $minImpr],
            );
        } catch (\Throwable $e) {
            $io->note('GSC-таблица недоступна — выборка без приоритета по показам.');
            $ids = $this->db->fetchFirstColumn(
                "SELECT id FROM brand b WHERE {$defectWhere} ORDER BY id LIMIT {$limit}",
            );
        }

        if ($ids === []) {
            $io->success('Дефектной meta не найдено.');
            return Command::SUCCESS;
        }

        $repaired = 0;
        foreach ($ids as $id) {
            $brand = $this->em->find(Brand::class, (int) $id);
            if ($brand === null || $brand->getStatus() !== Statuses::Active) {
                continue;
            }

            $changes = [];

            $title = (string) $brand->getMetaTitle();
            if ($title === '' || mb_strlen($title) > $maxT) {
                // пустой → собираем; слишком длинный → тримим по границе слова
                $newTitle = $title === ''
                    ? $this->seoMeta->buildTitle((string) $brand->getTitle(), $brand->getCity())
                    : $this->seoMeta->fit($title, $maxT);
                $changes['title'] = [$title, $newTitle];
                if (!$dryRun) {
                    $brand->setMetaTitle($newTitle);
                }
            }

            $desc = (string) $brand->getMetaDescription();
            if ($desc === '' || mb_strlen($desc) > $maxD) {
                $newDesc = $desc === ''
                    ? $this->seoMeta->buildDescription($brand->getDescription() ?: $brand->getAnons(), (string) $brand->getTitle(), $brand->getCity())
                    : $this->seoMeta->fit($desc, $maxD);
                $changes['description'] = [$desc, $newDesc];
                if (!$dryRun) {
                    $brand->setMetaDescription($newDesc);
                }
            }

            if ($changes === []) {
                continue;
            }

            if (!$dryRun) {
                $brand->setUpdatedAt(new \DateTime());
                $this->em->flush();
            }
            $repaired++;

            $io->section(sprintf('%s (#%d)', $brand->getTitle(), $brand->getId()));
            foreach ($changes as $field => [$was, $now]) {
                $io->text(sprintf('  %s: «%s» (%d) → «%s» (%d)',
                    $field, mb_substr($was, 0, 70), mb_strlen($was), $now, mb_strlen($now)));
            }
        }

        $io->newLine();
        $dryRun
            ? $io->note(sprintf('dry-run: починили бы %d брендов', $repaired))
            : $io->success(sprintf('Починено брендов: %d', $repaired));

        return Command::SUCCESS;
    }
}
