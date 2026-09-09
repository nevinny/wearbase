<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\NativeDeviceSession;
use App\Entity\User;
use App\Service\NativeDeviceAuth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/wardrobe-app/auth', name: 'api_wardrobe_native_auth_')]
final class NativeDeviceAuthController extends AbstractController
{
    public function __construct(private readonly NativeDeviceAuth $auth) {}

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request, RateLimiterFactory $nativeAuthLoginLimiter): JsonResponse
    {
        $data = $this->body($request);
        $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
        $key = hash('sha256', ($request->getClientIp() ?? 'unknown').'|'.mb_strtolower($email));
        if (!$nativeAuthLoginLimiter->create($key)->consume()->isAccepted()) {
            return $this->jsonPrivate(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $deviceId = is_string($data['deviceId'] ?? null) ? trim($data['deviceId']) : '';
        $deviceLabel = is_string($data['deviceLabel'] ?? null) ? trim($data['deviceLabel']) : NativeDeviceSession::LABEL_OTHER;
        if ($email === '' || $password === '' || strlen($password) > 4096 || strlen($deviceId) < 8 || strlen($deviceId) > 128) {
            return $this->jsonPrivate(['error' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
        }
        if (!in_array($deviceLabel, NativeDeviceSession::LABELS, true)) {
            return $this->jsonPrivate(['error' => 'invalid_device_label'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->jsonPrivate($this->auth->login($email, $password, $deviceId, $deviceLabel));
        } catch (\DomainException) {
            return $this->jsonPrivate(['error' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/devices', name: 'devices', methods: ['GET'])]
    public function devices(Request $request): JsonResponse
    {
        $user = $this->nativeUser($request);
        if ($user === null) {
            return $this->jsonPrivate(['error' => 'native_authentication_required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->jsonPrivate(['devices' => $this->auth->devices($user, $request->attributes->getInt('_native_device_session_id'))]);
    }

    #[Route('/devices/{publicId}', name: 'revoke_device', requirements: ['publicId' => '[a-f0-9]{32}'], methods: ['DELETE'])]
    public function revokeDevice(string $publicId, Request $request): JsonResponse
    {
        $user = $this->nativeUser($request);
        if ($user === null) {
            return $this->jsonPrivate(['error' => 'native_authentication_required'], Response::HTTP_UNAUTHORIZED);
        }
        try {
            $this->auth->revokeByPublicId($publicId, $user);
        } catch (\DomainException) {
            return $this->jsonPrivate(['error' => 'device_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->jsonPrivate(['ok' => true]);
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, RateLimiterFactory $nativeAuthRefreshLimiter): JsonResponse
    {
        if (!$nativeAuthRefreshLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->jsonPrivate(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $data = $this->body($request);
        $refreshToken = is_string($data['refreshToken'] ?? null) ? $data['refreshToken'] : '';
        if ($refreshToken === '' || strlen($refreshToken) > 256) {
            return $this->jsonPrivate(['error' => 'invalid_refresh_token'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->jsonPrivate($this->auth->refresh($refreshToken));
        } catch (\DomainException) {
            return $this->jsonPrivate(['error' => 'invalid_refresh_token'], Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $sessionId = $request->attributes->getInt('_native_device_session_id');
        if (!$user instanceof User || $sessionId < 1) {
            return $this->jsonPrivate(['error' => 'native_authentication_required'], Response::HTTP_UNAUTHORIZED);
        }
        $this->auth->revokeSession($sessionId, $user);

        return $this->jsonPrivate(['ok' => true]);
    }

    #[Route('/revoke-all', name: 'revoke_all', methods: ['POST'])]
    public function revokeAll(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || $request->attributes->getInt('_native_device_session_id') < 1) {
            return $this->jsonPrivate(['error' => 'native_authentication_required'], Response::HTTP_UNAUTHORIZED);
        }
        $this->auth->revokeAll($user);

        return $this->jsonPrivate(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function jsonPrivate(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json($data, $status, ['Cache-Control' => 'no-store, private']);
    }

    private function nativeUser(Request $request): ?User
    {
        $user = $this->getUser();

        return $user instanceof User && $request->attributes->getInt('_native_device_session_id') > 0 ? $user : null;
    }
}
