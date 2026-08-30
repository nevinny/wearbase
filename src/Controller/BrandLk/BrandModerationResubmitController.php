<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\BrandModeration;
use App\Repository\BrandModerationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Владелец самрег-бренда возвращает заявку на повторную проверку после того,
 * как исправил карточку по замечаниям (changes_requested) — до этого пути назад
 * в очередь не было, заявка зависала навсегда. Тот же путь и для archived
 * (писатель этого статуса — в следующем PR).
 */
class BrandModerationResubmitController extends BrandDashboardController
{
    private const COOLDOWN = '-1 hour';

    #[Route('/brand/moderation/resubmit', name: 'brand_moderation_resubmit', methods: ['POST'])]
    public function resubmit(Request $request, BrandModerationRepository $moderations, EntityManagerInterface $em): Response
    {
        $brand = $this->getActiveBrand();

        if (!$this->isCsrfTokenValid('moderation_resubmit', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_dashboard');
        }

        $moderation = $moderations->findOneByBrand($brand);
        if ($moderation === null || !in_array($moderation->getStatus(), [BrandModeration::STATUS_CHANGES_REQUESTED, BrandModeration::STATUS_ARCHIVED], true)) {
            $this->addFlash('warning', 'Заявка не требует повторной проверки.');
            return $this->redirectToRoute('brand_dashboard');
        }

        if ($moderation->getUpdatedAt() > new \DateTimeImmutable(self::COOLDOWN)) {
            $this->addFlash('warning', 'Повторная проверка уже недавно запрашивалась, подождите.');
            return $this->redirectToRoute('brand_dashboard');
        }

        $moderation->setStatus(BrandModeration::STATUS_QUEUED);
        $moderation->setAnalyzeAttempts(0); // иначе строку навечно скипает анализатор (attempts>=3)
        $em->flush();

        $this->addFlash('success', 'Карточка отправлена на повторную проверку.');
        return $this->redirectToRoute('brand_dashboard');
    }
}
