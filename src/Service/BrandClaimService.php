<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\EmailNotifier;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Подтверждение владения брендом: какие методы доступны, выдача доступа,
 * генерация/проверка email-кода.
 *
 * Авто-выдача доступа (grantOwnership без админа) включается флагами
 * autoGrantEmail / autoGrantVk — чтобы можно было отключить без правки кода.
 */
class BrandClaimService
{
    private const CODE_TTL_MINUTES   = 15;
    private const SEND_COOLDOWN_SEC  = 60;
    private const MAX_SENDS          = 5;
    private const MAX_ATTEMPTS       = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandUserRepository    $brandUserRepo,
        private readonly SubscriptionFactory    $subscriptionFactory,
        private readonly NotificationDispatcher $notifier,
        private readonly EmailNotifier          $emailNotifier,
        private readonly VkVerifier             $vkVerifier,
        private readonly bool $autoGrantEmail = true,
        private readonly bool $autoGrantVk = true,
    ) {}

    // ── Доступные методы ───────────────────────────────────────────────────

    /** @return string[] список ключей методов (BrandClaim::METHOD_*) */
    public function availableMethods(Brand $brand): array
    {
        $methods = [];
        if ($brand->getEmail()) {
            $methods[] = BrandClaim::METHOD_EMAIL_CODE;
        }
        if ($this->vkVerifier->isConfigured() && $this->brandVkGroup($brand) !== null) {
            $methods[] = BrandClaim::METHOD_VK_ADMIN;
        }

        return $methods;
    }

    public function isAutoGrant(string $method): bool
    {
        return match ($method) {
            BrandClaim::METHOD_EMAIL_CODE => $this->autoGrantEmail,
            BrandClaim::METHOD_VK_ADMIN   => $this->autoGrantVk,
            default                       => false,
        };
    }

    /**
     * VK screen-name группы из ссылок бренда. Определяем по host URL,
     * а не по brand_link.link_type (он часто пуст после обогащения).
     * Личные профили (id…) тоже вернутся — фактическую «группность»
     * проверит VkVerifier через API.
     */
    public function brandVkGroup(Brand $brand): ?string
    {
        foreach ($brand->getLinks() as $link) {
            $url = (string) $link->getLinkUrl();
            if (preg_match('~vk\.com/([A-Za-z0-9_.]+)~i', $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    // ── Защита: бренд уже принадлежит другому пользователю ──────────────────

    public function brandHasOtherOwner(Brand $brand, User $user): bool
    {
        $owners = $this->brandUserRepo->findBy(['brand' => $brand, 'role' => BrandUser::ROLE_OWNER]);
        foreach ($owners as $owner) {
            if ($owner->getUser() !== $user) {
                return true;
            }
        }

        return false;
    }

    // ── Выдача доступа (используется и админ-апрувом, и self-serve) ──────────

    public function grantOwnership(BrandClaim $claim, ?User $admin = null, ?string $via = null): void
    {
        $brand = $claim->getBrand();
        $user  = $claim->getUser();

        // BrandUser(owner) — идемпотентно
        $existing = $this->brandUserRepo->findOneBy(['brand' => $brand, 'user' => $user]);
        if (!$existing) {
            $brandUser = new BrandUser();
            $brandUser->setBrand($brand);
            $brandUser->setUser($user);
            $brandUser->setRole(BrandUser::ROLE_OWNER);
            $brandUser->setAcceptedAt(new \DateTimeImmutable());
            $this->em->persist($brandUser);
        }

        // Роли
        $roles = $user->getRoles();
        foreach (['ROLE_BRAND_MANAGER', 'ROLE_BRAND_OWNER'] as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        $user->setRoles(array_values(array_unique($roles)));

        // Free-trial подписка (идемпотентна внутри фабрики)
        $this->subscriptionFactory->createFreeTrial($brand);

        $claim->setStatus(BrandClaim::STATUS_APPROVED);
        if ($via !== null) {
            $claim->setVerifiedVia($via);
        }
        if ($admin !== null) {
            $claim->setReviewedBy($admin);
        }
        $claim->setReviewedAt(new \DateTimeImmutable());

        // dispatch только persist'ит in-app уведомление (без flush) — коммитим одним flush ниже
        $this->notifier->dispatch(
            $user,
            Notification::TYPE_SYSTEM,
            "Заявка на бренд «{$brand->getTitle()}» одобрена!",
            "Поздравляем! Вы стали владельцем бренда «{$brand->getTitle()}».",
            ['brand_id' => $brand->getId(), 'claim_id' => $claim->getId()],
            'brand_claim_approved',
            ['claim' => $claim],
        );

        $this->em->flush();
    }

    // ── Email-код ───────────────────────────────────────────────────────────

    /** @return 'sent'|'cooldown'|'limit'|'no_email' */
    public function startEmailCode(BrandClaim $claim): string
    {
        $brand = $claim->getBrand();
        $email = $brand?->getEmail();
        if (!$email) {
            return 'no_email';
        }

        $now = new \DateTimeImmutable();
        $sentAt = $claim->getCodeSentAt();
        if ($sentAt && ($now->getTimestamp() - $sentAt->getTimestamp()) < self::SEND_COOLDOWN_SEC) {
            return 'cooldown';
        }
        if ($claim->getCodeSends() >= self::MAX_SENDS) {
            return 'limit';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $claim->setMethod(BrandClaim::METHOD_EMAIL_CODE);
        $claim->setVerificationCode($code);
        $claim->setCodeExpiresAt($now->modify('+' . self::CODE_TTL_MINUTES . ' minutes'));
        $claim->setCodeSentAt($now);
        $claim->setCodeSends($claim->getCodeSends() + 1);
        $claim->setCodeAttempts(0);
        $this->em->flush();

        $this->emailNotifier->send(
            $email,
            'Код подтверждения владения брендом — WEARBASE',
            'brand_claim_code',
            ['code' => $code, 'brand' => $brand],
        );

        return 'sent';
    }

    /** @return 'ok'|'expired'|'too_many'|'mismatch'|'no_code' */
    public function checkEmailCode(BrandClaim $claim, string $input): string
    {
        $code = $claim->getVerificationCode();
        $expiresAt = $claim->getCodeExpiresAt();
        if (!$code || !$expiresAt) {
            return 'no_code';
        }
        if ($claim->getCodeAttempts() >= self::MAX_ATTEMPTS) {
            return 'too_many';
        }
        if (new \DateTimeImmutable() > $expiresAt) {
            return 'expired';
        }

        $claim->setCodeAttempts($claim->getCodeAttempts() + 1);

        if (!hash_equals($code, trim($input))) {
            $this->em->flush();
            return 'mismatch';
        }

        // Успех — обнуляем код
        $claim->setVerificationCode(null);
        $claim->setCodeExpiresAt(null);
        $this->em->flush();

        return 'ok';
    }
}
