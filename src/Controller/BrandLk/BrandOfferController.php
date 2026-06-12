<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\BrandUser;
use App\Entity\OfferAcceptance;
use App\Entity\OfferDocument;
use App\Repository\BrandUserRepository;
use App\Repository\OfferAcceptanceRepository;
use App\Repository\OfferDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Акцепт оферты продавца. Принимать должен авторизованный представитель —
 * владелец бренда (ROLE_OWNER в BrandUser). Менеджеров не принуждаем.
 */
#[Route('/brand/offer', name: 'brand_offer')]
class BrandOfferController extends BrandDashboardController
{
    #[Route('', name: '')]
    public function index(
        Request $request,
        OfferDocumentRepository $offers,
        OfferAcceptanceRepository $acceptances,
        BrandUserRepository $brandUsers,
    ): Response {
        $brand = $this->getActiveBrand();
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $offer = $this->currentSellerOffer($offers, $request);
        $isOwner = $brandUsers->findOneBy(['brand' => $brand, 'user' => $user])?->getRole() === BrandUser::ROLE_OWNER;

        return $this->render('brand_lk/offer.html.twig', [
            'brand'     => $brand,
            'offer'     => $offer,
            'accepted'  => $offer !== null && $acceptances->hasAccepted($user, $offer),
            'is_owner'  => $isOwner,
        ]);
    }

    #[Route('/accept', name: '_accept', methods: ['POST'])]
    public function accept(
        Request $request,
        OfferDocumentRepository $offers,
        OfferAcceptanceRepository $acceptances,
        BrandUserRepository $brandUsers,
        EntityManagerInterface $em,
    ): Response {
        $brand = $this->getActiveBrand();
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('accept_offer', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_offer');
        }

        // Принимать оферту вправе только владелец бренда
        $isOwner = $brandUsers->findOneBy(['brand' => $brand, 'user' => $user])?->getRole() === BrandUser::ROLE_OWNER;
        if (!$isOwner) {
            $this->addFlash('error', 'Оферту может принять только владелец бренда');
            return $this->redirectToRoute('brand_offer');
        }

        $offer = $this->currentSellerOffer($offers, $request);
        if ($offer === null) {
            $this->addFlash('error', 'Действующая оферта продавца не найдена');
            return $this->redirectToRoute('brand_offer');
        }

        if (!$acceptances->hasAccepted($user, $offer)) {
            $acceptance = new OfferAcceptance();
            $acceptance->setUser($user);
            $acceptance->setOfferDocument($offer);
            $acceptance->setContextType(OfferAcceptance::CONTEXT_SELLER_ONBOARDING);
            $acceptance->setIp($request->getClientIp());
            $acceptance->setUserAgent(substr((string) $request->headers->get('User-Agent'), 0, 255) ?: null);
            $em->persist($acceptance);
            $em->flush();
        }

        $this->addFlash('success', 'Оферта продавца принята');
        return $this->redirectToRoute('brand_offer');
    }

    /** Действующая редакция оферты продавца: текущая локаль, фолбэк на ru. */
    private function currentSellerOffer(OfferDocumentRepository $offers, Request $request): ?OfferDocument
    {
        return $offers->findCurrentPublished(OfferDocument::TYPE_SELLER_OFFER, $request->getLocale())
            ?? $offers->findCurrentPublished(OfferDocument::TYPE_SELLER_OFFER, 'ru');
    }
}
