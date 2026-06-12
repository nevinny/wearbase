<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LandingLead;
use App\Notification\EmailNotifier;
use App\Repository\ArticleRepository;
use App\Repository\BrandRepository;
use App\Repository\PaymentProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LandingController extends AbstractController
{
    #[Route('/{_locale}/without-marketplaces', name: 'landing_no_marketplace', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function noMarketplace(BrandRepository $repo): Response
    {
        $totalBrands = $repo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $featuredBrands = $repo->findFeaturedBrands(8, withLogo: true);

        return $this->render('tailwind/landing/no-marketplace.html.twig', [
            'totalBrands'   => (int) $totalBrands,
            'featuredBrands'=> $featuredBrands,
        ]);
    }

    #[Route('/{_locale}/for-brands', name: 'landing_for_brands', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function forBrands(BrandRepository $repo, PaymentProviderRepository $providers): Response
    {
        $totalBrands = $repo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('tailwind/landing/for-brands.html.twig', [
            'totalBrands'      => (int) $totalBrands,
            'paymentProviders' => $providers->findActive(),
        ]);
    }

    #[Route('/{_locale}/marketplace-commissions', name: 'landing_marketplace_fees', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function marketplaceFees(BrandRepository $repo, ArticleRepository $articles, Request $request): Response
    {
        // Контент переехал в блог; пока статья не опубликована (например, на проде), рендерим старый лендинг
        $article = $articles->findOnePublishedBySlug('komissii-marketpleysov-2026', $request->getLocale());
        if ($article) {
            return $this->redirectToRoute('blog_show', [
                '_locale' => $request->getLocale(),
                'slug' => $article->getSlug(),
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $totalBrands = $repo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('tailwind/landing/marketplace-fees.html.twig', [
            'totalBrands' => (int) $totalBrands,
        ]);
    }

    #[Route('/landing/lead', name: 'landing_lead', methods: ['POST'])]
    public function leadCapture(Request $request, EntityManagerInterface $em, EmailNotifier $notifier): Response
    {
        $token = $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('landing_lead', $token)) {
            $this->addFlash('error', 'Сессия истекла, попробуйте ещё раз');
            return $this->redirect($request->headers->get('referer', '/'));
        }

        $email = trim((string) $request->request->get('email', ''));
        $source = trim((string) $request->request->get('source', 'no-marketplace'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Введите корректный email');
            return $this->redirect($request->headers->get('referer', '/'));
        }

        $existing = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $this->addFlash('info', 'Вы уже оставляли заявку — мы скоро свяжемся с вами');
            return $this->redirect($request->headers->get('referer', '/'));
        }

        $lead = new LandingLead();
        $lead->setEmail($email);
        $lead->setSource($source);
        $em->persist($lead);
        $em->flush();

        $notifier->send(
            $notifier->getAdminEmail(),
            'Новый лид с лендинга — ' . $email,
            'new_lead',
            ['email' => $email, 'source' => $source]
        );

        $this->addFlash('success', 'Спасибо! Мы свяжемся с вами в ближайшее время.');
        return $this->redirect($request->headers->get('referer', '/'));
    }
}
