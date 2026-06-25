<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandLink;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Бэкафилл сайта бренда из own_site-источника (детерминированно, БЕЗ LLM). Сайт у нас
 * часто уже известен (own_site найден на discover и скраплен), но не сохранён как
 * ссылка — карточка остаётся без сайта, хотя email/др. есть. Команда создаёт
 * BrandLink(link_type='website') из лучшего живого own_site URL у брендов без такой
 * ссылки. Идемпотентна (бренды с website-ссылкой пропускаются).
 *
 *   php bin/console app:brand:backfill-website --dry-run
 *   php bin/console app:brand:backfill-website
 */
#[AsCommand(
    name: 'app:brand:backfill-website',
    description: 'Сайт бренда из own_site-источника → BrandLink website (детерминированно, без LLM)',
)]
class BackfillWebsiteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection             $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум брендов за прогон', 10000)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что будет проставлено, без записи')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $limit  = max(1, (int) $input->getArgument('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        // Все own_site брендов без website-ссылки; лучший на бренд выберем в PHP.
        $raw = $this->db->fetchAllAssociative(
            "SELECT u.brand_id, u.url, COALESCE(u.relevance_score,0) rel, u.http_status http
             FROM brand_source_url u
             WHERE u.source_type = 'own_site'
               AND NOT EXISTS (SELECT 1 FROM brand_link l WHERE l.brand_id = u.brand_id AND l.link_type = 'website')
             ORDER BY u.brand_id",
        );

        // Группируем по бренду, выбираем лучший URL: живой (http<400) > выше relevance > короче (homepage).
        $byBrand = [];
        foreach ($raw as $r) {
            $byBrand[(int) $r['brand_id']][] = $r;
        }
        $rows = [];
        foreach ($byBrand as $brandId => $cands) {
            usort($cands, static function ($a, $b) {
                $aDead = ($a['http'] !== null && (int) $a['http'] >= 400) ? 1 : 0;
                $bDead = ($b['http'] !== null && (int) $b['http'] >= 400) ? 1 : 0;
                return $aDead <=> $bDead
                    ?: (float) $b['rel'] <=> (float) $a['rel']
                    ?: mb_strlen((string) $a['url']) <=> mb_strlen((string) $b['url']);
            });
            $rows[] = ['brand_id' => $brandId, 'url' => $cands[0]['url']];
            if (count($rows) >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            $io->success('Нет брендов для бэкафилла (у всех с own_site уже есть website-ссылка).');
            return Command::SUCCESS;
        }

        $io->title('Бэкафилл сайта брендов из own_site');
        $io->text(sprintf('Кандидатов: %d%s', count($rows), $dryRun ? ' (dry-run)' : ''));

        $done = 0;
        foreach ($rows as $i => $r) {
            $brandId = (int) $r['brand_id'];
            $url     = trim((string) $r['url']);
            if ($url === '') {
                continue;
            }
            if ($dryRun) {
                if ($i < 30) {
                    $io->text(sprintf('  brand #%d → %s', $brandId, $url));
                }
                $done++;
                continue;
            }

            $brand = $this->em->find(Brand::class, $brandId);
            if ($brand === null) {
                continue;
            }
            $url = mb_substr($url, 0, 255);
            $link = (new BrandLink())
                ->setBrand($brand)
                ->setLinkType('website')
                ->setLinkUrl($url);
            $link->setTitle('website');                          // DefaultFields: slug+title NOT NULL
            $link->setSlug(substr(md5('website' . $url), 0, 24));
            $link->setStatus(Statuses::Active);
            $this->em->persist($link);
            $done++;
            if ($done % 200 === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }
        $io->success(sprintf('%s website-ссылок: %d', $dryRun ? 'К проставлению' : 'Проставлено', $done));

        return Command::SUCCESS;
    }
}
