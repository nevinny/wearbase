<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NativeDeviceSession;
use App\Entity\NativeRefreshToken;
use App\Entity\User;
use App\Repository\NativeDeviceSessionRepository;
use App\Repository\NativeRefreshTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NativeDeviceAuth
{
    private const ACCESS_TTL = 900;
    private const REFRESH_TTL = 2_592_000;

    public function __construct(
        private readonly UserRepository $users,
        private readonly NativeDeviceSessionRepository $sessions,
        private readonly NativeRefreshTokenRepository $refreshTokens,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array{accessToken:string,accessExpiresAt:string,refreshToken:string,refreshExpiresAt:string,device:array{publicId:string,label:string}} */
    public function login(string $email, string $password, string $deviceId, string $deviceLabel = NativeDeviceSession::LABEL_OTHER): array
    {
        $email = mb_strtolower(trim($email));
        $user = $this->users->findOneBy(['email' => $email]);
        $passwordValid = $user instanceof User
            ? $this->passwordHasher->isPasswordValid($user, $password)
            : $this->passwordHasher->isPasswordValid($this->dummyUser(), $password);
        if (!$user instanceof User || !$passwordValid || $user->getStatus() !== 'active') {
            throw new \DomainException('invalid_credentials');
        }

        return $this->em->wrapInTransaction(function () use ($user, $deviceId, $deviceLabel): array {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $deviceHash = $this->hash($deviceId);
            foreach ($this->sessions->findActiveForDevice($user, $deviceHash) as $existing) {
                $existing->revoke();
            }

            return $this->issue($user, $deviceHash, $deviceLabel);
        });
    }

    /** @return array{accessToken:string,accessExpiresAt:string,refreshToken:string,refreshExpiresAt:string} */
    public function refresh(string $rawRefreshToken): array
    {
        $result = $this->em->wrapInTransaction(function () use ($rawRefreshToken): array {
            $token = $this->refreshTokens->findForUpdate($this->hash($rawRefreshToken));
            if ($token === null) {
                throw new \DomainException('invalid_refresh_token');
            }
            $session = $token->getSession();
            if ($token->isUsed()) {
                $session->revoke();
                $this->em->flush();
                return ['reuseDetected' => true];
            }
            $now = new \DateTimeImmutable();
            if ($session->isRevoked() || $session->getUser()->getStatus() !== 'active' || $token->getExpiresAt() <= $now) {
                throw new \DomainException('invalid_refresh_token');
            }

            $token->markUsed();
            [$access, $accessExpiresAt] = $this->newToken(self::ACCESS_TTL, 32);
            [$refresh, $refreshExpiresAt] = $this->newToken(self::REFRESH_TTL, 48);
            $session->rotateAccess($this->hash($access), $accessExpiresAt);
            $session->touch();
            $next = new NativeRefreshToken($session, $this->hash($refresh), $refreshExpiresAt);
            $session->addRefreshToken($next);
            $this->em->persist($next);
            $this->em->flush();

            return $this->response($access, $accessExpiresAt, $refresh, $refreshExpiresAt);
        });
        if (isset($result['reuseDetected'])) {
            throw new \DomainException('invalid_refresh_token');
        }

        return $result;
    }

    public function authenticateAccess(string $rawAccessToken): ?NativeDeviceSession
    {
        $session = $this->sessions->findValidAccess($this->hash($rawAccessToken), new \DateTimeImmutable());
        if ($session?->getUser()->getStatus() === 'active') {
            $this->sessions->touchLastUsed($session, new \DateTimeImmutable());

            return $session;
        }

        return null;
    }

    /** @return array<int, array{publicId:string,label:string,createdAt:string,lastUsedAt:?string,revokedAt:?string,current:bool}> */
    public function devices(User $user, int $currentSessionId): array
    {
        return array_map(static fn (NativeDeviceSession $session): array => [
            'publicId' => $session->getPublicId(),
            'label' => $session->getDeviceLabel(),
            'createdAt' => $session->getCreatedAt()->format(DATE_ATOM),
            'lastUsedAt' => $session->getLastUsedAt()?->format(DATE_ATOM),
            'revokedAt' => $session->getRevokedAt()?->format(DATE_ATOM),
            'current' => $session->getId() === $currentSessionId,
        ], $this->sessions->findForUser($user));
    }

    public function revokeByPublicId(string $publicId, User $user): void
    {
        $session = $this->sessions->findOwnedByPublicId($user, $publicId);
        if ($session === null) {
            throw new \DomainException('invalid_device_session');
        }
        $session->revoke();
        $this->em->flush();
    }

    public function revokeSession(int $sessionId, User $user): void
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null || $session->getUser()->getId() !== $user->getId()) {
            throw new \DomainException('invalid_device_session');
        }
        $session->revoke();
        $this->em->flush();
    }

    public function revokeAll(User $user): void
    {
        foreach ($this->sessions->findActiveForUser($user) as $session) {
            $session->revoke();
        }
        $this->em->flush();
    }

    /** @return array{accessToken:string,accessExpiresAt:string,refreshToken:string,refreshExpiresAt:string,device:array{publicId:string,label:string}} */
    private function issue(User $user, string $deviceHash, string $deviceLabel): array
    {
        [$access, $accessExpiresAt] = $this->newToken(self::ACCESS_TTL, 32);
        [$refresh, $refreshExpiresAt] = $this->newToken(self::REFRESH_TTL, 48);
        $session = new NativeDeviceSession($user, $deviceHash, $this->hash($access), $accessExpiresAt, $deviceLabel);
        $refreshEntity = new NativeRefreshToken($session, $this->hash($refresh), $refreshExpiresAt);
        $session->addRefreshToken($refreshEntity);
        $this->em->persist($session);
        $this->em->persist($refreshEntity);
        $this->em->flush();

        return $this->response($access, $accessExpiresAt, $refresh, $refreshExpiresAt, $session);
    }

    /** @return array{0:string,1:\DateTimeImmutable} */
    private function newToken(int $ttl, int $bytes): array
    {
        return [rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='), new \DateTimeImmutable("+{$ttl} seconds")];
    }

    /** @return array{accessToken:string,accessExpiresAt:string,refreshToken:string,refreshExpiresAt:string,device?:array{publicId:string,label:string}} */
    private function response(string $access, \DateTimeImmutable $accessExpiresAt, string $refresh, \DateTimeImmutable $refreshExpiresAt, ?NativeDeviceSession $session = null): array
    {
        $response = [
            'accessToken' => $access,
            'accessExpiresAt' => $accessExpiresAt->format(DATE_ATOM),
            'refreshToken' => $refresh,
            'refreshExpiresAt' => $refreshExpiresAt->format(DATE_ATOM),
        ];
        if ($session !== null) {
            $response['device'] = ['publicId' => $session->getPublicId(), 'label' => $session->getDeviceLabel()];
        }

        return $response;
    }

    private function hash(string $value): string { return hash('sha256', $value); }

    private function dummyUser(): User
    {
        return (new User())->setPassword('$2y$13$M8w7f4/4cNT0izPCBMd36eGX5e.Sy7m7r5gJdYKqPCCQyJxUxoG2K');
    }
}
