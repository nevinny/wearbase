<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BrandUser;
use App\Entity\Subscription;
use App\Entity\Tariff;
use App\Repository\BrandUserRepository;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/payment', name: 'payment_')]
class PaymentController extends AbstractController
{
    #[Route('/yookassa/webhook', name: 'yookassa_webhook', methods: ['POST'])]
    public function yookassaWebhook(
        Request $request,
        PaymentService $paymentService,
    ): Response {
        if (!PaymentService::isYooIp($request->getClientIp() ?? '')) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $json = $request->getContent();
        $ok = $paymentService->handleNotification($json);

        if (!$ok) {
            return new JsonResponse(['error' => 'processing_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/subscribe/{subscriptionId}', name: 'subscribe', methods: ['POST'])]
    #[IsGranted('ROLE_BRAND_MANAGER')]
    public function subscribe(
        int $subscriptionId,
        EntityManagerInterface $em,
        PaymentService $paymentService,
        BrandUserRepository $brandUserRepo,
        Request $request,
    ): Response {
        $subscription = $em->getRepository(Subscription::class)->find($subscriptionId);
        if (!$subscription) {
            throw $this->createNotFoundException();
        }

        // Проверка владения брендом
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $brandUser = $brandUserRepo->findOneBy([
            'user' => $user,
            'brand' => $subscription->getBrand(),
        ]);
        if (!$brandUser) {
            throw $this->createAccessDeniedException();
        }

        // CSRF
        if (!$this->isCsrfTokenValid('subscribe', $request->request->get('_token'))) {
            $this->addFlash('error', 'Сессия истекла');
            return $this->redirectToRoute('brand_setting');
        }

        // Целевой (выбранный) тариф
        $tariff = $em->getRepository(Tariff::class)->find((int) $request->request->get('tariff_id'));
        if (!$tariff || !$tariff->isActive() || (float) $tariff->getPriceRub() <= 0) {
            $this->addFlash('error', 'Тариф недоступен');
            return $this->redirectToRoute('brand_setting');
        }

        $brand = $subscription->getBrand();
        $returnUrl = $this->generateUrl('brand_setting', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $description = sprintf('Подписка %s — %s', $tariff->getName(), $brand?->getTitle());

        $paymentUrl = $paymentService->createSubscriptionPayment($subscription, $tariff, $returnUrl, $description);

        if ($paymentUrl === null) {
            $this->addFlash('error', 'Не удалось создать платёж');
            return $this->redirectToRoute('brand_setting');
        }

        return $this->redirect($paymentUrl);
    }
}
