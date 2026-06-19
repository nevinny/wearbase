<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Единая проверка «оператор-админ» для публичных (main-firewall) страниц.
 *
 * Админом считаем:
 *  1) текущего main-юзера (App\User) с ROLE_ADMIN, либо
 *  2) сессию, залогиненную на firewall `admin` (admincore) — её токен лежит в
 *     сессии под ключом `_security_admin`. Этот firewall сам гейтнут ROLE_ADMIN
 *     (access_control ^/admin), поэтому аутентифицированный токен там = админ.
 *
 * Это убирает требование двойного входа (оператор обычно сидит в /admin).
 */
final class AdminAccess
{
    private const ADMIN_SESSION_KEY = '_security_admin';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    public function isAdmin(): bool
    {
        // 1) main-firewall: App\User с ROLE_ADMIN
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // 2) admincore-сессия на firewall `admin`.
        // hasPreviousSession(): читаем сессию ТОЛЬКО если она уже была (есть cookie).
        // Иначе getSession() стартовал бы сессию на каждый анон-/бот-запрос публичной
        // страницы → лишние Set-Cookie и срыв полностраничного кеша/SEO.
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasPreviousSession()) {
            return false;
        }
        $raw = $request->getSession()->get(self::ADMIN_SESSION_KEY);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $token = @unserialize($raw, ['allowed_classes' => true]);
        } catch (\Throwable) {
            return false;
        }

        return $token instanceof TokenInterface && $token->getUser() !== null;
    }
}
