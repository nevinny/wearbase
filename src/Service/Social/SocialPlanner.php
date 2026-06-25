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

    /** Авто-рубрики (template, без бренда) для дней, где в сетке только held-рубрики (UGC/lifestyle). */
    private const AUTO_FALLBACK = ['manifesto', 'vs_marketplace', 'calculator'];

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
        // Стартуем с числа уже созданных брендовых постов — курсор продвигается между прогонами,
        // иначе он сбрасывался в 0 и каждый запуск брал pool[0] (одни и те же бренды повторялись).
        $brandCursor = $this->posts->countWithBrand();
        $created = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $today->modify("+{$offset} day");
            $weekday = (int) $day->format('N');

            $hasAuto = false;
            foreach ($this->rubrics->forWeekday($weekday) as $rubric) {
                $def = $this->rubrics->get($rubric);
                if ($def['auto']) {
                    $hasAuto = true;
                }
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

            // День без авто-рубрик (только held UGC/lifestyle) → добиваем авто-постом, чтобы не пустовал.
            // Рубрика детерминирована датой (idempotent: тот же день → тот же fallback → existsForSlot гейтит).
            if (!$hasAuto) {
                $fallback = self::AUTO_FALLBACK[(int) $day->format('z') % count(self::AUTO_FALLBACK)];
                if ($this->planFallback($channel, $fallback, $day, $dryRun)) {
                    $created++;
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $created;
    }

    /** Создаёт авто-fallback-пост (template-рубрика без бренда) для пустого дня. true если создан. */
    private function planFallback(SocialChannel $channel, string $rubric, \DateTimeImmutable $day, bool $dryRun): bool
    {
        $def = $this->rubrics->get($rubric);
        if ($def === null || $this->posts->existsForSlot($channel, $rubric, \DateTime::createFromInterface($day))) {
            return false;
        }

        $post = (new SocialPost())
            ->setChannel($channel)
            ->setRubric($rubric)
            ->setMediaType($def['media'])
            ->setScheduledAt(\DateTime::createFromInterface($day->setTime($def['hour'], 0)))
            ->setStatus(SocialPost::STATUS_PLANNED);

        if (!$dryRun) {
            $this->em->persist($post);
        }

        return true;
    }
}
