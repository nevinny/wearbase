<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrandClaim;
use App\Entity\Notification;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandClaimRepository;
use App\Service\BrandClaimService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/brand-claims', name: 'admin_brand_claims')]
#[IsGranted('ROLE_ADMIN')]
class BrandClaimAdminController extends AbstractController
{
    public function __construct(
        private readonly BrandClaimRepository   $claimRepo,
        private readonly BrandClaimService      $claimService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $notifier,
    ) {}

    #[Route('', name: '')]
    public function index(): Response
    {
        $pending = $this->claimRepo->findPending();
        $recent  = $this->em->getRepository(BrandClaim::class)
            ->createQueryBuilder('c')
            ->where('c.status IN (:done)')
            ->setParameter('done', [BrandClaim::STATUS_APPROVED, BrandClaim::STATUS_REJECTED])
            ->orderBy('c.reviewedAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()->getResult();

        return $this->render('admin/brand_claims/index.html.twig', [
            'pending' => $pending,
            'recent'  => $recent,
        ]);
    }

    #[Route('/approve/{id}', name: '_approve', methods: ['POST'])]
    public function approve(BrandClaim $claim, Request $request): Response
    {
        if (!in_array($claim->getStatus(), [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED], true)) {
            $this->addFlash('error', 'Заявка уже обработана');
            return $this->redirectToRoute('admin_brand_claims');
        }

        $note = trim((string) $request->request->get('admin_note', ''));

        /** @var \App\Entity\User|null $admin */
        $admin = $this->getUser();
        $claim->setAdminNote($note ?: null);
        $this->claimService->grantOwnership(
            $claim,
            $admin instanceof \App\Entity\User ? $admin : null,
            'admin',
        );

        $this->addFlash('success', sprintf(
            '%s → владелец бренда «%s»',
            $claim->getUser()->getEmail(),
            $claim->getBrand()->getTitle()
        ));

        return $this->redirectToRoute('admin_brand_claims');
    }

    #[Route('/reject/{id}', name: '_reject', methods: ['POST'])]
    public function reject(BrandClaim $claim, Request $request): Response
    {
        $note = trim((string) $request->request->get('admin_note', ''));

        $claim->setStatus(BrandClaim::STATUS_REJECTED);
        $claim->setAdminNote($note ?: null);
        $claim->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->notifier->dispatch(
            $claim->getUser(),
            Notification::TYPE_SYSTEM,
            "Заявка на бренд «{$claim->getBrand()->getTitle()}» отклонена",
            $note ? "Причина: {$note}" : null,
            ['brand_id' => $claim->getBrand()->getId(), 'claim_id' => $claim->getId()],
            'brand_claim_rejected',
            ['claim' => $claim],
        );
        // dispatch только persist'ит in-app — коммитим
        $this->em->flush();

        $this->addFlash('success', 'Заявка отклонена');
        return $this->redirectToRoute('admin_brand_claims');
    }
}
