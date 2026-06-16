<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Brand;
use App\Entity\BrandContentRevision;
use App\Repository\BrandContentRevisionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Версионирование контента бренда (append-only) + старт closed-loop эксперимента.
 *
 * Живые значения — в brand.* (read-path не трогаем); активная ревизия их зеркалит.
 * Поток смены контента: ensureBaseline() (не потерять текущее) → пишем новый brand.* →
 * record() (новая активная ревизия с baseline-GSC и окном замера). Промоутить ТОЛЬКО
 * прошедшее quality-gate. Откат — append-only (новая ревизия source=rollback).
 *
 * Не делает flush — это ответственность вызывающего батча (em->clear() между брендами).
 */
class BrandContentVersioner
{
    public const WINDOW_DAYS = 14;

    /**
     * Длина окна замера по номеру попытки: молодым страницам Google нужен разгон,
     * поэтому первые окна длиннее (меньше ложных loss на недокрученной выдаче), дальше — 14.
     * attempt 1 (первая генерация) → 28, attempt 2 (после 1-го регена) → 21, далее → 14.
     */
    private const WINDOW_BY_ATTEMPT = [1 => 28, 2 => 21];

    public static function windowDays(int $attempt): int
    {
        return self::WINDOW_BY_ATTEMPT[$attempt] ?? self::WINDOW_DAYS;
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandContentRevisionRepository $repo,
        private readonly Connection $db,
    ) {}

    /**
     * Снять текущее состояние brand.* как ревизию, если истории ещё нет —
     * чтобы при первой же перегенерации не потерять legacy-текст.
     */
    public function ensureBaseline(Brand $brand, string $source = BrandContentRevision::SOURCE_LEGACY): void
    {
        if ($this->repo->hasAny($brand)) {
            return;
        }
        $rev = $this->snapshot($brand, $source)->setActive(true);
        $this->captureGscBefore($rev, $brand);
        // baseline — не эксперимент: считаем текущим эталоном, не оцениваем и не откатываем
        $rev->setVerdict(BrandContentRevision::VERDICT_WIN);
        $rev->setMeasureAfter(null);
        $this->em->persist($rev);
    }

    /**
     * Зафиксировать новую активную ревизию (вызывать ПОСЛЕ записи нового brand.* и
     * прохождения quality-gate). Стартует эксперимент: baseline GSC + окно замера.
     */
    public function record(
        Brand $brand,
        string $source,
        bool $grounded = false,
        ?float $score = null,
        ?string $note = null,
    ): BrandContentRevision {
        $this->ensureBaseline($brand);
        $prev = $this->repo->findActive($brand);
        $attempt = $prev ? $prev->getAttempt() + 1 : 1;

        $rev = $this->snapshot($brand, $source)
            ->setGrounded($grounded)
            ->setRetrievalScore($score)
            ->setNote($note)
            ->setActive(true)
            ->setPrevRevisionId($prev?->getId())
            ->setAttempt($attempt)
            ->setVerdict(BrandContentRevision::VERDICT_PENDING)
            ->setMeasureAfter((new \DateTime())->modify('+' . self::windowDays($attempt) . ' days'));

        $this->captureGscBefore($rev, $brand);

        if ($prev !== null) {
            $prev->setActive(false);
        }
        $this->em->persist($rev);

        return $rev;
    }

    /**
     * Откат: пишем новую активную ревизию (source=rollback) с контентом цели и
     * возвращаем тройку в brand.*. История append-only — старые ревизии не трогаем.
     */
    public function rollback(Brand $brand, BrandContentRevision $target, ?string $note = null): BrandContentRevision
    {
        $brand->setDescription($target->getDescription());
        $brand->setMetaTitle($target->getMetaTitle());
        $brand->setMetaDescription($target->getMetaDescription());

        $prev = $this->repo->findActive($brand);
        if ($prev !== null) {
            $prev->setActive(false);
        }

        $rev = (new BrandContentRevision())
            ->setBrand($brand)
            ->setDescription($target->getDescription())
            ->setMetaTitle($target->getMetaTitle())
            ->setMetaDescription($target->getMetaDescription())
            ->setSource(BrandContentRevision::SOURCE_ROLLBACK)
            ->setGrounded($target->isGrounded())
            ->setRetrievalScore($target->getRetrievalScore())
            ->setActive(true)
            ->setPrevRevisionId($target->getId())
            ->setVerdict(BrandContentRevision::VERDICT_WIN)   // откат к известному рабочему — не эксперимент
            ->setNote($note ?? ('откат к ревизии #' . $target->getId()));
        $this->em->persist($rev);

        return $rev;
    }

    private function snapshot(Brand $brand, string $source): BrandContentRevision
    {
        return (new BrandContentRevision())
            ->setBrand($brand)
            ->setDescription($brand->getDescription())
            ->setMetaTitle($brand->getMetaTitle())
            ->setMetaDescription($brand->getMetaDescription())
            ->setSource($source);
    }

    /** Baseline GSC за окно: показы/клики (gsc_page_stats) + факт индексации (gsc_index_status). */
    private function captureGscBefore(BrandContentRevision $rev, Brand $brand): void
    {
        [$impr, $clicks, $indexed] = $this->gscSnapshot((int) $brand->getId());
        $rev->setGscImprBefore($impr);
        $rev->setGscClicksBefore($clicks);
        $rev->setGscIndexedBefore($indexed);
    }

    /**
     * @return array{0:int,1:int,2:bool} [impressions, clicks, indexed] за последние WINDOW_DAYS.
     * GSC-таблицы живут на Mac; на проде пусто → вернёт 0/false (эксперименты считаем на Mac).
     */
    public function gscSnapshot(int $brandId): array
    {
        $since = (new \DateTime('-' . self::WINDOW_DAYS . ' days'))->format('Y-m-d');
        // SUM/MAX по пустому набору → NULL; PHP-касты ниже ((int)/(bool)) превращают в 0/false.
        $row = $this->db->fetchAssociative(
            'SELECT SUM(impressions) impr, SUM(clicks) clicks
             FROM gsc_page_stats WHERE brand_id = :id AND day >= :since',
            ['id' => $brandId, 'since' => $since],
        ) ?: ['impr' => 0, 'clicks' => 0];
        $indexed = (bool) $this->db->fetchOne(
            'SELECT MAX(indexed) FROM gsc_index_status WHERE brand_id = :id',
            ['id' => $brandId],
        );

        return [(int) $row['impr'], (int) $row['clicks'], $indexed];
    }
}
