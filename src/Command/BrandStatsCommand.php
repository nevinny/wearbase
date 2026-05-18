<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:brand:stats',
    description: 'Статистика по брендам',
)]
class BrandStatsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $conn = $this->entityManager->getConnection();

        // Total brands
        $total = $conn->executeQuery("SELECT COUNT(*) FROM brand WHERE status = 'active'")->fetchOne();

        // With/without description
        $withDescription = $conn->executeQuery(
            "SELECT COUNT(*) FROM brand WHERE status = 'active' AND description IS NOT NULL AND description != ''"
        )->fetchOne();

        // With/without anons
        $withAnons = $conn->executeQuery(
            "SELECT COUNT(*) FROM brand WHERE status = 'active' AND anons IS NOT NULL AND anons != ''"
        )->fetchOne();

        // With city
        $withCity = $conn->executeQuery(
            "SELECT COUNT(*) FROM brand WHERE status = 'active' AND city IS NOT NULL AND city != ''"
        )->fetchOne();

        // With logo
        $withLogo = $conn->executeQuery(
            "SELECT COUNT(*) FROM brand WHERE status = 'active' AND logo IS NOT NULL AND logo != ''"
        )->fetchOne();

        // Top cities
        $topCities = $conn->executeQuery(
            "SELECT city, COUNT(*) as cnt FROM brand WHERE status = 'active' AND city IS NOT NULL GROUP BY city ORDER BY cnt DESC LIMIT 10"
        )->fetchAllAssociative();

        // Brands without any content (no description, no anons, no city)
        $emptyBrands = $conn->executeQuery(
            "SELECT COUNT(*) FROM brand WHERE status = 'active' AND (description IS NULL OR description = '') AND (anons IS NULL OR anons = '') AND (city IS NULL OR city = '')"
        )->fetchOne();

        $io->title('📊 Статистика брендов WEARBASE');

        $io->table(
            ['Метрика', 'Количество', '%'],
            [
                ['Всего брендов', $total, '100%'],
                ['С описанием', $withDescription, round($withDescription / $total * 100, 1) . '%'],
                ['Без описания', $total - $withDescription, round(($total - $withDescription) / $total * 100, 1) . '%'],
                ['С анонсом', $withAnons, round($withAnons / $total * 100, 1) . '%'],
                ['С городом', $withCity, round($withCity / $total * 100, 1) . '%'],
                ['С логотипом', $withLogo, round($withLogo / $total * 100, 1) . '%'],
                ['Пустые (нет описания, анонса, города)', $emptyBrands, round($emptyBrands / $total * 100, 1) . '%'],
            ]
        );

        if ($topCities) {
            $io->section('🏙️ Топ-10 городов');
            $io->table(
                ['Город', 'Брендов'],
                array_map(fn($row) => [$row['city'], $row['cnt']], $topCities)
            );
        }

        // Sample empty brands
        $sampleEmpty = $conn->executeQuery(
            "SELECT title, slug, city FROM brand WHERE status = 'active' AND (description IS NULL OR description = '') AND (anons IS NULL OR anons = '') LIMIT 5"
        )->fetchAllAssociative();

        if ($sampleEmpty) {
            $io->section('📝 Примеры пустых брендов');
            $io->table(
                ['Название', 'Slug', 'Город'],
                array_map(fn($row) => [$row['title'], $row['slug'], $row['city'] ?: '-'], $sampleEmpty)
            );
        }

        return Command::SUCCESS;
    }
}