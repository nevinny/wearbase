<?php

namespace App\Service\Social;

use App\Entity\Brand;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\BrandRepository;
use App\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Планирует посты на N дней вперёд по сетке SocialRubrics: создаёт social_post(planned)
 * (или held для ручных рубрик). Дедуп по слоту (канал+рубрика+день). Расписание — в MSK
 * (консистентно с cron-раннером и MySQL NOW(); грабли tz см. [[llm-ollama-server]]).
 */
class SocialPlanner
{
    private const BRAND_POOL = 40;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialPostRepository $posts,
        private readonly BrandRepository $brands,
        private readonly SocialRubrics $rubrics,
    ) {
    }

    /**
     * @return int сколько постов создано
     */
    public function planAhead(SocialChannel $channel, int $days, bool $dryRun = false): int
    {
        $tz = new \DateTimeZone('Europe/Moscow');
        $today = new \DateTimeImmutable('today', $tz);

        /** @var Brand[] $pool пул брендов с логотипом для брендовых рубрик */
        $pool = $this->brands->findFeaturedBrands(self::BRAND_POOL, true);
        $brandCursor = 0;
        $created = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $today->modify("+{$offset} day");
            $weekday = (int) $day->format('N');

            foreach ($this->rubrics->forWeekday($weekday) as $rubric) {
                $def = $this->rubrics->get($rubric);
                $dayStart = \DateTime::createFromInterface($day);

                if ($this->posts->existsForSlot($channel, $rubric, $dayStart)) {
                    continue;
                }

                $brand = null;
                if ($def['needsBrand']) {
                    if ($pool === []) {
                        continue; // нет брендов с логотипом — пропускаем брендовую рубрику
                    }
                    $brand = $pool[$brandCursor % count($pool)];
                    $brandCursor++;
                }

                $scheduledAt = \DateTime::createFromInterface($day->setTime($def['hour'], 0));

                $post = (new SocialPost())
                    ->setChannel($channel)
                    ->setBrand($brand)
                    ->setRubric($rubric)
                    ->setMediaType($def['media'])
                    ->setScheduledAt($scheduledAt)
                    ->setStatus($def['auto'] ? SocialPost::STATUS_PLANNED : SocialPost::STATUS_HELD);

                if (!$dryRun) {
                    $this->em->persist($post);
                }
                $created++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $created;
    }
}
