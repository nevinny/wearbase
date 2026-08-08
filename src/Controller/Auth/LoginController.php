<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('account_dashboard');
        }

        $lastUsername = $authenticationUtils->getLastUsername();
        if (str_ends_with($lastUsername, '@' . User::MANAGED_EMAIL_DOMAIN)) {
            $lastUsername = '';
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Этот метод перехватывается Symfony Security.');
    }
}
