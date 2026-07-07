<?php

namespace App\Command;

use App\Service\Seo\ArticleDistributionAttacher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Привязка готовых копий статьи под площадку (var/seo/**\/*.md, суффикс имени
 * -{platform}(-pN)?.md — другая персона/тон, см. GenerateListicleCommand::
 * PLATFORM_TONES) к статьям блога, в article_distribution (версионируемо).
 * Логика — в ArticleDistributionAttacher (общая с автопривязкой внутри
 * app:seo:publish-blog).
 *
 * Без platform — сканирует ВЕСЬ var/seo и привязывает копии под все найденные
 * площадки разом (это же прогоняет ежедневный крон, см. миграцию
 * Version20260707_attach_distribution_cron).
 *
 *   php bin/console app:seo:attach-distribution --dry-run       # все площадки
 *   php bin/console app:seo:attach-distribution                 # все площадки
 *   php bin/console app:seo:attach-distribution dzen             # только dzen (тоже auto-discovery по var/seo)
 *   php bin/console app:seo:attach-distribution vc --dir=var/seo/vc   # точечно, без auto-discovery
 */
#[AsCommand(
    name: 'app:seo:attach-distribution',
    description: 'Привязка var/seo/**/*.md к статьям блога (article_distribution), по умолчанию все площадки',
)]
class AttachArticleDistributionCommand extends Command
{
    public function __construct(
        private readonly ArticleDistributionAttacher $attacher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('platform', InputArgument::OPTIONAL, 'Код площадки (dzen, vc, …) — без аргумента обрабатываются все найденные под var/seo')
            ->addOption('dir',     null, InputOption::VALUE_REQUIRED, 'Явная папка вместо авто-обнаружения по var/seo (нужен вместе с platform)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать план без записи')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $platform = $input->getArgument('platform');
        $dir      = $input->getOption('dir');
        $dryRun   = (bool) $input->getOption('dry-run');

        if ($dir !== null && $platform === null) {
            $io->error('--dir требует явного аргумента platform.');
            return Command::FAILURE;
        }

        if ($dir !== null) {
            $files = glob(rtrim($dir, '/') . '/*.md') ?: [];
            $grouped = [$platform => $files];
        } else {
            $grouped = $this->attacher->discoverFiles('var/seo');
            if ($platform !== null) {
                $grouped = array_intersect_key($grouped, [$platform => true]);
            }
        }

        if ($grouped === [] || array_sum(array_map('count', $grouped)) === 0) {
            $io->error($platform !== null
                ? "Не нашёл файлов площадки «{$platform}» под var/seo."
                : 'Не нашёл файлов ни одной площадки под var/seo.');
            return Command::FAILURE;
        }

        foreach ($grouped as $p => $files) {
            $result = $this->attacher->attachPlatform($p, $files, $dryRun);

            foreach ($result['warnings'] as $warning) {
                $io->warning($warning);
            }

            $io->section("Площадка «{$p}»");
            $io->table(['slug', 'source_file', 'version'], $result['rows']);
            $io->success(sprintf(
                'новых версий: %d · без изменений: %d · дублей блога (пропущены): %d · статей без копии: %d · файлов без статьи: %d',
                $result['attached'], $result['unchanged'], $result['duplicates'], $result['unmatchedArticles'], count($result['unmatchedFiles']),
            ));

            if ($result['unmatchedFiles'] !== []) {
                $io->note('Файлы без статьи-первоисточника (проверить slug/source_file): ' . implode(', ', $result['unmatchedFiles']));
            }
        }

        return Command::SUCCESS;
    }
}
