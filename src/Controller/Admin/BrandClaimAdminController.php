<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrandClaim;
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

        try {
            $this->claimService->grantOwnership(
                $claim,
                $admin instanceof \App\Entity\User ? $admin : null,
                'admin',
            );
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_brand_claims');
        }

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
        $note = trim((string) $request->request->get('admin_note', '')) ?: null;

        /** @var \App\Entity\User|null $admin */
        $admin = $this->getUser();

        if (!$this->claimService->reject($claim, $admin instanceof \App\Entity\User ? $admin : null, $note)) {
            $this->addFlash('error', 'Заявка уже обработана');
            return $this->redirectToRoute('admin_brand_claims');
        }

        $this->addFlash('success', 'Заявка отклонена');
        return $this->redirectToRoute('admin_brand_claims');
    }
}
