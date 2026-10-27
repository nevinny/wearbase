<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\Notification\AdminNotifier;
use App\Notification\EmailNotifier;
use App\Service\BrandClaimService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmailVerificationController extends AbstractController
{
    #[Route('/verify-email/resend', name: 'app_resend_verification')]
    public function resend(EntityManagerInterface $em, EmailNotifier $emailNotifier): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isEmailVerified()) {
            $this->addFlash('info', 'Email уже подтверждён');
            return $this->redirectToRoute('account_dashboard');
        }

        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($token);
        $em->flush();

        $emailNotifier->send(
            $user,
            'Подтвердите email — WEARBASE',
            'verify_email',
            ['token' => $token],
        );

        $this->addFlash('success', 'Письмо с подтверждением отправлено повторно');
        return $this->redirectToRoute('account_dashboard');
    }

    #[Route('/verify-email/{token}', name: 'app_verify_email')]
    public function verify(
        string $token,
        EntityManagerInterface $em,
        BrandClaimService $claimService,
        AdminNotifier $adminNotifier,
    ): Response {
        $user = $em->getRepository(User::class)->findOneBy(['emailVerificationToken' => $token]);

        if (!$user) {
            $this->addFlash('error', 'Недействительная ссылка подтверждения');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isEmailVerified()) {
            $this->addFlash('info', 'Email уже подтверждён');
            return $this->redirectToRoute('account_dashboard');
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationToken(null);
        $em->flush();

        $this->addFlash('success', 'Email успешно подтверждён!');

        $granted = $claimService->grantDeferredClaims($user);
        foreach ($granted as $claim) {
            $brandTitle = (string) $claim->getBrand()->getTitle();
            $adminNotifier->send(sprintf(
                "✅ <b>Бренд забрали</b> «%s»\nВладелец: %s (%s, авто-выдача после подтверждения почты)",
                htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $user->getEmail(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $claim->getVerifiedVia(), ENT_QUOTES, 'UTF-8'),
            ));
            $this->addFlash('success', "Владение подтверждено! Вы стали владельцем бренда «{$brandTitle}».");
        }

        return $this->redirectToRoute($granted ? 'brand_dashboard' : 'account_dashboard');
    }
}
