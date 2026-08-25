<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NewsSource;
use App\Repository\NewsSourceRepository;
use App\Service\News\NewsSourcesCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Сид справочника источников MVP (идемпотентный upsert по name).
 * Те же 6 записей, что кладёт миграция Version20260825_news_pipeline:
 * 4 facts_only активных + 2 forbidden выключенных (_docs/news-sources-tos.md §3).
 */
#[AsCommand(name: 'app:news:sources:seed', description: 'Сид справочника источников новостей (4 facts_only + 2 forbidden)')]
final class NewsSourcesSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsSourceRepository $sources,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = 0;
        $updated = 0;

        foreach (NewsSourcesCatalog::all() as $spec) {
            $source = $this->sources->findOneBy(['name' => $spec['name']]);
            if ($source === null) {
                $source = new NewsSource();
                $source->setName($spec['name']);
                ++$created;
            } else {
                ++$updated;
            }

            $source->setFeedUrl($spec['feedUrl'])
                ->setTosMode($spec['tosMode'])
                ->setActive($spec['active'])
                ->setRubricHint($spec['rubricHint']);
            $this->em->persist($source);
        }

        $this->em->flush();
        $output->writeln(sprintf('news_source: %d created, %d updated', $created, $updated));

        return Command::SUCCESS;
    }
}
