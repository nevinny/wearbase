<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\Notification\EmailNotifier;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ForgotPasswordController extends AbstractController
{
    #[Route('/forgot-password', name: 'app_forgot_password_request')]
    public function request(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        EmailNotifier $emailNotifier,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('account_dashboard');
        }

        $sent = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('app_forgot_password_request');
            }

            $email = trim((string) $request->request->get('email'));
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $user = $userRepo->findOneBy(['email' => $email]);
                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $user->setPasswordResetToken($token);
                    $user->setPasswordResetRequestedAt(new \DateTimeImmutable());
                    $em->flush();

                    $emailNotifier->send(
                        $user,
                        'Восстановление пароля — WEARBASE',
                        'reset_password',
                        ['token' => $token],
                    );
                }
            }
            $sent = true;
        }

        return $this->render('auth/forgot_password.html.twig', [
            'sent' => $sent,
        ]);
    }
}
