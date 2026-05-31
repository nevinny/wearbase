<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ResetPasswordController extends AbstractController
{
    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function reset(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $em->getRepository(User::class)->findOneBy(['passwordResetToken' => $token]);

        if (!$user || !$user->getPasswordResetRequestedAt()) {
            $this->addFlash('error', 'Недействительная ссылка сброса пароля');
            return $this->redirectToRoute('app_login');
        }

        // Token expires after 1 hour
        $expiresAt = $user->getPasswordResetRequestedAt()->modify('+1 hour');
        if (new \DateTimeImmutable() > $expiresAt) {
            $user->setPasswordResetToken(null);
            $user->setPasswordResetRequestedAt(null);
            $em->flush();

            $this->addFlash('error', 'Ссылка устарела. Запросите сброс пароля заново.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            $password = $request->request->get('password');
            if (!$password || strlen($password) < 8) {
                $this->addFlash('error', 'Пароль должен быть не менее 8 символов');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            $user->setPassword($hasher->hashPassword($user, $password));
            $user->setPasswordResetToken(null);
            $user->setPasswordResetRequestedAt(null);
            $em->flush();

            $this->addFlash('success', 'Пароль успешно изменён. Теперь вы можете войти.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset_password.html.twig', [
            'token' => $token,
        ]);
    }
}
