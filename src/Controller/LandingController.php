<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LandingLead;
use App\Entity\ServicePayment;
use App\Notification\AdminNotifier;
use App\Notification\EmailNotifier;
use App\Repository\ArticleRepository;
use App\Repository\BrandRepository;
use App\Repository\PaymentProviderRepository;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        // Название и ссылка — опциональны на уровне роута: форму `landing_lead` шлют три лендинга,
        // и только `for-brands` спрашивает бренд (required в разметке). Без них лид всё равно пишем,
        // иначе no-marketplace / marketplace-fees перестали бы собирать почту (sales_offer.md §11).
        $brandName = trim((string) $request->request->get('brand_name', ''));
        $website = trim((string) $request->request->get('website', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Введите корректный email');
            return $this->redirect($request->headers->get('referer', '/'));
        }

        $existing = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        if ($existing) {
            // Повторная заявка — не дублируем, но дозаполняем то, чего в прошлый раз не спросили.
            if ($brandName !== '' && $existing->getBrandName() === null) {
                $existing->setBrandName($brandName);
            }
            if ($website !== '' && $existing->getWebsite() === null) {
                $existing->setWebsite($website);
            }
            $em->flush();

            $this->addFlash('info', 'Вы уже оставляли заявку — мы скоро свяжемся с вами');
            return $this->redirect($request->headers->get('referer', '/'));
        }

        $lead = new LandingLead();
        $lead->setEmail($email);
        $lead->setSource($source);
        $lead->setBrandName($brandName !== '' ? $brandName : null);
        $lead->setWebsite($website !== '' ? $website : null);
        $em->persist($lead);
        $em->flush();

        // ⚠️ Ключ `email` в контексте TemplatedEmail зарезервирован (Symfony бросает исключение, а
        // EmailNotifier soft-fail'ит его в лог) — из-за этого письмо админу про лид не уходило вообще.
        // Название переменной менять нельзя обратно: только leadEmail.
        $notifier->send(
            $notifier->getAdminEmail(),
            'Новый лид с лендинга — ' . $email,
            'new_lead',
            ['leadEmail' => $email, 'source' => $source, 'brandName' => $brandName, 'website' => $website]
        );

        // Автоответ самому лиду: без него человек оставлял почту и не получал НИЧЕГО — путь в ЛК
        // (`/register?brand=1`, пароль задаёт сам) существовал, но нигде ему не показывался.
        $notifier->send(
            $email,
            'Ваш кабинет бренда на WEARBASE — как войти',
            'lead_welcome',
            ['brandName' => $brandName]
        );

        $this->addFlash('success', 'Спасибо! Мы свяжемся с вами в ближайшее время.');
        return $this->redirect($request->headers->get('referer', '/'));
    }

    /**
     * Онлайн-оплата услуги «Размещение под ключ» 5 000₽ (sales_offer.md §3 TODO) — платформенный
     * путь YooKassa (доход площадки, как подписки), не через шлюз бренда. Без готовых ключей
     * YooKassa (dev/test) деградирует в редирект на форму заявки — не 500.
     */
    #[Route('/{_locale}/for-brands/placement/pay', name: 'landing_placement_pay', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'], methods: ['POST'])]
    public function placementPay(
        Request $request,
        EntityManagerInterface $em,
        PaymentService $paymentService,
        RateLimiterFactory $servicePayLimiter,
    ): Response {
        $locale = $request->getLocale();

        $token = $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('placement_pay', $token)) {
            return new Response('Invalid CSRF token', Response::HTTP_BAD_REQUEST);
        }

        if (!$servicePayLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('Too many requests', 429);
        }

        $email = trim((string) $request->request->get('email', ''));
        $brandHint = trim((string) $request->request->get('brand_hint', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Укажите корректный email для оплаты');
            return $this->redirectToRoute('landing_for_brands_placement', ['_locale' => $locale]);
        }

        if (!$paymentService->isConfigured()) {
            $this->addFlash('error', 'Онлайн-оплата временно недоступна — оставьте заявку, мы свяжемся с вами');
            return $this->redirectToRoute('landing_for_brands_placement', ['_locale' => $locale]);
        }

        $servicePayment = new ServicePayment();
        $servicePayment->setServiceCode(ServicePayment::SERVICE_PLACEMENT);
        $servicePayment->setEmail($email);
        $servicePayment->setBrandHint($brandHint !== '' ? $brandHint : null);
        $em->persist($servicePayment);
        $em->flush();

        $returnUrl = $this->generateUrl('landing_placement_paid', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
        $paymentUrl = $paymentService->createServicePayment(
            $servicePayment,
            $returnUrl,
            'Размещение бренда под ключ на WEARBASE',
        );

        if ($paymentUrl === null) {
            $this->addFlash('error', 'Платёжный шлюз временно недоступен. Оставьте заявку — свяжемся с вами.');
            return $this->redirectToRoute('landing_for_brands_placement', ['_locale' => $locale]);
        }

        return $this->redirect($paymentUrl);
    }

    /**
     * Страница возврата после оплаты услуги — фактический статус приходит вебхуком YooKassa
     * (PaymentService::handleNotification), здесь только нейтральное сообщение.
     */
    #[Route('/{_locale}/for-brands/placement/paid', name: 'landing_placement_paid', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function placementPaid(): Response
    {
        return $this->render('tailwind/landing/for-brands-placement-paid.html.twig');
    }
}
