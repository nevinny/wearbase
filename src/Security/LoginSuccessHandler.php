<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * После успешного входа:
 * - Бренд-менеджер/владелец → /brand/dashboard
 * - Обычный покупатель → /account
 * - Если был _target_path → туда
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
        private readonly CartRepository $cartRepo,
    ) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $this->mergeGuestCart($request, $token);

        // Приоритет 1: _target_path из сессии (Symfony сохраняет куда шёл)
        $targetPath = $request->getSession()->get('_security.main.target_path');
        /** @var User $user */
        $user = $token->getUser();
        $isBrandManager = $user instanceof User && $user->isBrandManager();

        if ($targetPath
            && !str_starts_with($targetPath, '/login')
            && !str_starts_with($targetPath, '/register')
            && (!str_starts_with($targetPath, '/brand') || $isBrandManager || str_starts_with($targetPath, '/brand-claim'))
        ) {
            return new RedirectResponse($targetPath);
        }

        // Приоритет 2: по роли
        /** @var User $user */
        $user = $token->getUser();

        if ($user instanceof User && $user->isBrandManager()) {
            return new RedirectResponse($this->urlGenerator->generate('brand_dashboard'));
        }

        return new RedirectResponse($this->urlGenerator->generate('account_dashboard'));
    }

    private function mergeGuestCart(Request $request, TokenInterface $token): void
    {
        $sessionId = $request->getSession()->getId();
        if (!$sessionId) {
            return;
        }

        $guestCart = $this->cartRepo->findOneBy(['sessionId' => $sessionId]);
        if (!$guestCart || $guestCart->isEmpty()) {
            return;
        }

        /** @var User $user */
        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $userCart = $this->cartRepo->findOneBy(['user' => $user]);

        if (!$userCart) {
            $guestCart->setUser($user);
            $guestCart->setSessionId(null);
            $this->em->flush();
            return;
        }

        foreach ($guestCart->getItems() as $item) {
            $existing = $userCart->findItemByVariant($item->getVariant());
            if ($existing) {
                $existing->setQty($existing->getQty() + $item->getQty());
                $guestCart->removeItem($item);
                $this->em->remove($item);
            } else {
                $userCart->addItem($item);
            }
        }

        $this->em->remove($guestCart);
        $this->em->flush();
    }
}
