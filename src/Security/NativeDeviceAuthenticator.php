<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\NativeDeviceAuth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class NativeDeviceAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly NativeDeviceAuth $auth) {}

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();
        if (in_array($path, ['/api/v1/wardrobe-app/auth/login', '/api/v1/wardrobe-app/auth/refresh'], true)) {
            return false;
        }

        return str_starts_with($path, '/api/v1/wardrobe-app/')
            && str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $raw = trim(substr((string) $request->headers->get('Authorization'), 7));
        $session = $raw === '' ? null : $this->auth->authenticateAccess($raw);
        if ($session === null) {
            throw new AuthenticationException('Invalid native access token');
        }
        $request->attributes->set('_native_device_session_id', $session->getId());

        return new SelfValidatingPassport(new UserBadge(
            (string) $session->getUser()->getUserIdentifier(),
            static fn () => $session->getUser(),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response { return null; }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'invalid_access_token'], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store, private']);
    }
}
