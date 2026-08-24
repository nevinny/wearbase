<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Family;
use App\Entity\FamilyInvite;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Семейный гардероб: членство, права доступа, managed-дети, инвайты.
 *
 * ВСЯ авторизация семейных операций живёт ЗДЕСЬ, а не в Voter'ах:
 * сервис переиспользуется Telegram-путём (webhook-команды), где нет
 * security-токена/контекста — Voter там просто не сработает.
 * AccessDeniedException из Security\Core кидается как обычное исключение
 * (в HTTP-контексте ядро превратит его в 403, в TG-пути ловится вызывающим).
 *
 * Семья создаётся лениво: при добавлении первого ребёнка или первом инвайте.
 * Создатель становится owner семьи и получает family_role = 'parent'.
 */
class FamilyService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Члены семьи пользователя, включая его самого; родители первыми.
     * Без семьи → [сам пользователь].
     *
     * @return User[]
     */
    public function membersFor(User $user): array
    {
        $family = $user->getFamily();
        if ($family === null) {
            return [$user];
        }

        $members = $this->em->getRepository(User::class)->findBy(['family' => $family]);

        usort($members, static function (User $a, User $b): int {
            $aParent = $a->isFamilyParent() ? 0 : 1;
            $bParent = $b->isFamilyParent() ? 0 : 1;
            return $aParent <=> $bParent ?: $a->getId() <=> $b->getId();
        });

        return $members;
    }

    /**
     * Может ли actor управлять гардеробом target: сам себя — всегда;
     * чужой — только при общей (non-null) семье и роли parent у actor'а.
     */
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->getId() === $target->getId()) {
            return true;
        }

        $actorFamily  = $actor->getFamily();
        $targetFamily = $target->getFamily();

        return $actorFamily !== null
            && $targetFamily !== null
            && $actorFamily->getId() === $targetFamily->getId()
            && $actor->isFamilyParent()
            && $target->getFamilyRole() === User::FAMILY_ROLE_CHILD;
    }

    /**
     * Резолв ?member=<id>: null → сам actor; иначе член семьи под управлением actor'а.
     *
     * @throws AccessDeniedException если target не найден или actor не вправе им управлять
     */
    public function resolveMember(User $actor, ?int $memberId): User
    {
        if ($memberId === null || $memberId === $actor->getId()) {
            return $actor;
        }

        $target = $this->em->getRepository(User::class)->find($memberId);
        if ($target === null || !$this->canManage($actor, $target)) {
            throw new AccessDeniedException('Нет доступа к этому гардеробу');
        }

        return $target;
    }

    /**
     * Managed-ребёнок: реальная строка client с синтетическим email и случайным
     * паролем; family_claim_token — для будущего claim'а («ребёнок дорос»).
     * Лениво создаёт семью, если у родителя её ещё нет.
     */
    public function createChild(User $parent, string $firstName, ?\DateTimeImmutable $birthDate = null): User
    {
        $family = $this->ensureFamilyAsParent($parent);

        $child = new User();
        $child->setEmail(sprintf(
            'child-%d-%s@%s',
            $family->getId(),
            bin2hex(random_bytes(4)),
            User::MANAGED_EMAIL_DOMAIN,
        ));
        $child->setPassword($this->passwordHasher->hashPassword($child, bin2hex(random_bytes(32))));
        $child->setRoles(['ROLE_USER']);
        $child->setFirstName($firstName);
        $child->setBirthDate($birthDate);
        $child->setFamily($family);
        $child->setFamilyRole(User::FAMILY_ROLE_CHILD);
        $child->issueFamilyClaim();

        $this->em->persist($child);
        $this->em->flush();

        return $child;
    }

    /**
     * Инвайт для человека со своей почтой (взрослый или подросший ребёнок).
     * Лениво создаёт семью, если её ещё нет.
     */
    public function createInvite(User $parent, string $role, ?string $intendedEmail = null): FamilyInvite
    {
        if (!in_array($role, [User::FAMILY_ROLE_PARENT, User::FAMILY_ROLE_CHILD], true)) {
            throw new \InvalidArgumentException('Недопустимая роль приглашения: ' . $role);
        }

        $family = $this->ensureFamilyAsParent($parent);

        $invite = new FamilyInvite();
        $invite->setFamily($family);
        $invite->setRole($role);
        $invite->setIntendedEmail($intendedEmail);

        $this->em->persist($invite);
        $this->em->flush();

        return $invite;
    }

    /**
     * Акцепт инвайта залогиненным пользователем без семьи. Одноразовый.
     *
     * @throws \DomainException инвайт уже использован / пользователь уже в семье
     */
    public function acceptInvite(User $user, FamilyInvite $invite): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $lockedInvite = $this->em->find(FamilyInvite::class, $invite->getId(), LockMode::PESSIMISTIC_WRITE);
            $lockedUser = $this->em->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedInvite instanceof FamilyInvite || !$lockedUser instanceof User) {
                throw new \DomainException('Приглашение использовано, отозвано или истекло');
            }
            $this->em->refresh($lockedInvite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($lockedUser, LockMode::PESSIMISTIC_WRITE);
            if (!$lockedInvite->isUsable()) {
                throw new \DomainException('Приглашение использовано, отозвано или истекло');
            }
            if ($lockedUser->getFamily() !== null) {
                throw new \DomainException('Вы уже состоите в семье');
            }
            if ($lockedInvite->getIntendedEmail() !== null
                && mb_strtolower((string) $lockedUser->getEmail()) !== $lockedInvite->getIntendedEmail()
            ) {
                throw new \DomainException('Приглашение предназначено для другого email');
            }

            $lockedUser->setFamily($lockedInvite->getFamily());
            $lockedUser->setFamilyRole($lockedInvite->getRole());
            $lockedInvite->setAcceptedAt(new \DateTimeImmutable());
            $lockedInvite->setAcceptedBy($lockedUser);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeInvite(User $actor, FamilyInvite $invite): void
    {
        if (!$actor->isFamilyParent() || $actor->getFamily()?->getId() !== $invite->getFamily()?->getId()) {
            throw new AccessDeniedException('Нет доступа к приглашению');
        }
        $invite->revoke($actor);
        $this->em->flush();
    }

    public function renewInvite(User $actor, FamilyInvite $invite): FamilyInvite
    {
        if (!$actor->isFamilyParent() || $actor->getFamily()?->getId() !== $invite->getFamily()?->getId()) {
            throw new AccessDeniedException('Нет доступа к приглашению');
        }

        $invite->revoke($actor);
        $renewed = new FamilyInvite();
        $renewed->setFamily($invite->getFamily());
        $renewed->setRole((string) $invite->getRole());
        $renewed->setIntendedEmail($invite->getIntendedEmail());
        $this->em->persist($renewed);
        $this->em->flush();

        return $renewed;
    }

    /**
     * Абсолютная ссылка «ребёнок дорос» (null, если ребёнок не managed / уже claimed).
     */
    public function claimUrl(User $child): ?string
    {
        $token = $child->getFamilyClaimToken();
        if ($token === null || !$child->isFamilyClaimUsable()) {
            return null;
        }

        return $this->urlGenerator->generate(
            'family_claim',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function renewChildAccess(User $actor, User $child): void
    {
        $this->assertParentManagesChild($actor, $child);
        $child->issueFamilyClaim();
        $this->em->flush();
    }

    public function revokeChildAccess(User $actor, User $child): void
    {
        $this->assertParentManagesChild($actor, $child);
        $child->revokeFamilyClaim();
        $this->em->flush();
    }

    public function activateChildAccess(User $child, string $email, string $password): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $lockedChild = $this->em->find(User::class, $child->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedChild instanceof User) {
                throw new \DomainException('Ссылка больше не действует');
            }
            $this->em->refresh($lockedChild, LockMode::PESSIMISTIC_WRITE);
            if (!$lockedChild->isFamilyClaimUsable()) {
                throw new \DomainException('Ссылка больше не действует');
            }
            $normalizedEmail = mb_strtolower(trim($email));
            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $normalizedEmail]);
            if ($existing !== null && $existing->getId() !== $lockedChild->getId()) {
                throw new \DomainException('Этот email уже зарегистрирован');
            }

            $lockedChild->setEmail($normalizedEmail);
            $lockedChild->setPassword($this->passwordHasher->hashPassword($lockedChild, $password));
            $lockedChild->setClaimedAt(new \DateTimeImmutable());
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function assertParentManagesChild(User $actor, User $child): void
    {
        if (!$actor->isFamilyParent()
            || !$this->canManage($actor, $child)
            || $child->getFamilyRole() !== User::FAMILY_ROLE_CHILD
            || !$child->isManaged()
        ) {
            throw new AccessDeniedException('Нет доступа к профилю ребёнка');
        }
    }

    public function inviteUrl(FamilyInvite $invite): string
    {
        return $this->urlGenerator->generate(
            'family_invite_accept',
            ['token' => $invite->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * Семья actor'а (лениво создавая её, actor становится owner+parent).
     *
     * @throws AccessDeniedException если actor — ребёнок в существующей семье
     */
    private function ensureFamilyAsParent(User $actor): Family
    {
        $family = $actor->getFamily();

        if ($family !== null) {
            if (!$actor->isFamilyParent()) {
                throw new AccessDeniedException('Управлять семьёй может только родитель');
            }
            return $family;
        }

        $family = new Family();
        $family->setOwner($actor);
        $actor->setFamily($family);
        $actor->setFamilyRole(User::FAMILY_ROLE_PARENT);

        $this->em->persist($family);
        $this->em->flush(); // сразу flush: id семьи нужен для синтетических email детей

        return $family;
    }
}
