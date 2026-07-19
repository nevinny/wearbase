<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LandingLead;
use App\Notification\AdminNotifier;
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

    /**
     * Услуга «Размещение под ключ» 5 000₽ разово (sales_offer.md §10) — отдельная посадочная,
     * НЕ трогает подписочный лендинг forBrands() выше. URL уходит в холодные письма — не переносить.
     */
    #[Route('/{_locale}/for-brands/placement', name: 'landing_for_brands_placement', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function forBrandsPlacement(BrandRepository $repo): Response
    {
        $exampleBrands = $repo->findFeaturedBrands(1, withLogo: true);

        return $this->render('tailwind/landing/for-brands-placement.html.twig', [
            'exampleBrand' => $exampleBrands[0] ?? null,
        ]);
    }

    #[Route('/{_locale}/for-brands/placement/lead', name: 'landing_placement_lead', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'], methods: ['POST'])]
    public function placementLead(Request $request, EntityManagerInterface $em, AdminNotifier $adminNotifier): Response
    {
        $locale = $request->getLocale();

        $token = $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('placement_lead', $token)) {
            $this->addFlash('error', 'Сессия истекла, попробуйте ещё раз');
            return $this->redirectToRoute('landing_for_brands_placement', ['_locale' => $locale]);
        }

        // Honeypot: скрытое поле, которое живой пользователь не заполняет. Заполнено → тихо
        // "успешный" редирект, без записи и уведомления — не подсказываем боту, что его поймали.
        if (trim((string) $request->request->get('company_site', '')) !== '') {
            return $this->redirectToRoute('landing_placement_thanks', ['_locale' => $locale]);
        }

        $brandName = trim((string) $request->request->get('brand_name', ''));
        $email     = trim((string) $request->request->get('email', ''));
        $website   = trim((string) $request->request->get('website', ''));

        if ($brandName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Заполните название бренда и корректный email');
            return $this->redirectToRoute('landing_for_brands_placement', ['_locale' => $locale]);
        }

        $existing = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        if (!$existing) {
            $lead = new LandingLead();
            $lead->setEmail($email);
            $lead->setSource('for-brands-placement');
            $lead->setBrandName($brandName);
            $lead->setWebsite($website !== '' ? $website : null);
            $em->persist($lead);
            $em->flush();

            $adminNotifier->send(sprintf(
                "\xF0\x9F\x92\xB0 <b>Заявка «Размещение под ключ»</b>\nБренд: %s\nEmail: %s\nСайт/соцсеть: %s",
                htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($website !== '' ? $website : '—', ENT_QUOTES, 'UTF-8'),
            ));
        }

        return $this->redirectToRoute('landing_placement_thanks', ['_locale' => $locale]);
    }

    #[Route('/{_locale}/for-brands/placement/thanks', name: 'landing_placement_thanks', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function placementThanks(): Response
    {
        return $this->render('tailwind/landing/for-brands-placement-thanks.html.twig');
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
