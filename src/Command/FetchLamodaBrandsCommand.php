<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:fetch:lamoda-brands',
    description: 'Fetch all brands from Lamoda API and save to JSON file',
)]
class FetchLamodaBrandsCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Fetching Lamoda brands...');

        try {
            $response = $this->httpClient->request('GET', 'https://www.lamoda.ru/api/v1/brands/list', [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                    'Accept' => 'application/json',
                    'Referer' => 'https://www.lamoda.ru/brands/',
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();
            $io->success(sprintf('Fetched %d brands from Lamoda', count($data)));

            // Extract just title and slug
            $brands = array_map(fn($b) => [
                'title' => $b['title'],
                'slug' => $b['seo_tail'] ?? '',
                'is_premium' => $b['is_premium'] ?? false,
                'is_kids' => $b['is_kids'] ?? false,
                'is_beauty' => $b['is_beauty'] ?? false,
                'is_sport' => $b['is_sport'] ?? false,
                'source' => 'lamoda.ru',
            ], $data);

            $outputFile = dirname(__DIR__, 2) . '/_sql/lamoda_brands_raw.json';
            file_put_contents($outputFile, json_encode($brands, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $io->success(sprintf('Saved to %s', $outputFile));
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
