<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'client')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
#[UniqueEntity(fields: ['email'], message: 'Этот email уже зарегистрирован')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const FAMILY_ROLE_PARENT = 'parent';
    public const FAMILY_ROLE_CHILD = 'child';

    // Домен синтетических email managed-детей (email NOT NULL UNIQUE не трогаем)
    public const MANAGED_EMAIL_DOMAIN = 'family.wearbase.local';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    #[Vich\UploadableField(mapping: 'user_avatar', fileNameProperty: 'avatar')]
    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Допустимы только форматы JPEG, PNG и WebP (SVG запрещён)',
        maxWidth: 5000,
        maxHeight: 5000,
        maxWidthMessage: 'Изображение слишком широкое (максимум {{ max_width }}px)',
        maxHeightMessage: 'Изображение слишком высокое (максимум {{ max_height }}px)',
    )]
    private ?File $avatarFile = null;

    // Telegram chat ID для уведомлений
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telegramChatId = null;

    // Токен для привязки Telegram через deep-link
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $telegramLinkToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetRequestedAt = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    // --- Семейный гардероб ---

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Family $family = null;

    // FAMILY_ROLE_PARENT | FAMILY_ROLE_CHILD | null (вне семьи)
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $familyRole = null;

    // Токен страницы /family/claim/{token}: есть только у managed-детей, обнуляется при claim
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $familyClaimToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $familyClaimExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $familyClaimRevokedAt = null;

    // Когда managed-ребёнок «дорос» и получил свои email+пароль
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(nullable: true)]
    private ?int $heightCm = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $clothingSize = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $shoeSize = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $profileNotes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $profileCompletedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, BrandUser>
     */
    #[ORM\OneToMany(targetEntity: BrandUser::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $brandUsers;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'customer')]
    private Collection $orders;

    /**
     * @var Collection<int, Address>
     */
    #[ORM\OneToMany(targetEntity: Address::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $addresses;

    /**
     * @var Collection<int, Notification>
     */
    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'recipient', orphanRemoval: true)]
    private Collection $notifications;

    /**
     * @var Collection<int, WardrobeItem>
     */
    #[ORM\OneToMany(targetEntity: WardrobeItem::class, mappedBy: 'user')]
    private Collection $wardrobeItems;

    /**
     * @var Collection<int, Wardrobe>
     */
    #[ORM\OneToMany(targetEntity: Wardrobe::class, mappedBy: 'owner')]
    private Collection $wardrobes;

    public function __construct()
    {
        $this->brandUsers = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->wardrobeItems = new ArrayCollection();
        $this->wardrobes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string { return (string) $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string { return $this->password; }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void {}

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->getUserIdentifier() === $user->getUserIdentifier()
            && $this->getRoles() === $user->getRoles();
    }

    public function getFirstName(): ?string { return $this->firstName; }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string { return $this->lastName; }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? '')) ?: $this->email;
    }

    public function getPhone(): ?string { return $this->phone; }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getAvatar(): ?string { return $this->avatar; }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;
        return $this;
    }

    public function setAvatarFile(?File $avatarFile = null): void
    {
        $this->avatarFile = $avatarFile;
        if ($avatarFile !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getAvatarFile(): ?File { return $this->avatarFile; }

    public function getTelegramChatId(): ?string { return $this->telegramChatId; }

    public function setTelegramChatId(?string $telegramChatId): static
    {
        $this->telegramChatId = $telegramChatId;
        return $this;
    }

    public function getTelegramLinkToken(): ?string { return $this->telegramLinkToken; }

    public function setTelegramLinkToken(?string $telegramLinkToken): static
    {
        $this->telegramLinkToken = $telegramLinkToken;
        return $this;
    }

    public function generateTelegramLinkToken(): string
    {
        $this->telegramLinkToken = bin2hex(random_bytes(32));
        return $this->telegramLinkToken;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
        return $this;
    }

    public function isEmailVerified(): bool { return $this->emailVerifiedAt !== null; }

    public function getEmailVerificationToken(): ?string { return $this->emailVerificationToken; }

    public function setEmailVerificationToken(?string $emailVerificationToken): static
    {
        $this->emailVerificationToken = $emailVerificationToken;
        return $this;
    }

    public function getPasswordResetToken(): ?string { return $this->passwordResetToken; }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;
        return $this;
    }

    public function getPasswordResetRequestedAt(): ?\DateTimeImmutable { return $this->passwordResetRequestedAt; }

    public function setPasswordResetRequestedAt(?\DateTimeImmutable $passwordResetRequestedAt): static
    {
        $this->passwordResetRequestedAt = $passwordResetRequestedAt;
        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getBrandUsers(): Collection { return $this->brandUsers; }

    public function getOrders(): Collection { return $this->orders; }

    public function getAddresses(): Collection { return $this->addresses; }

    public function getNotifications(): Collection { return $this->notifications; }

    public function getWardrobeItems(): Collection { return $this->wardrobeItems; }

    public function getWardrobes(): Collection { return $this->wardrobes; }

    public function getDefaultAddress(): ?Address
    {
        foreach ($this->addresses as $address) {
            if ($address->isDefault()) {
                return $address;
            }
        }
        return $this->addresses->first() ?: null;
    }

    public function getFamily(): ?Family { return $this->family; }

    public function setFamily(?Family $family): static
    {
        $this->family = $family;
        return $this;
    }

    public function getFamilyRole(): ?string { return $this->familyRole; }

    public function setFamilyRole(?string $familyRole): static
    {
        $this->familyRole = $familyRole;
        return $this;
    }

    public function isFamilyParent(): bool
    {
        return $this->familyRole === self::FAMILY_ROLE_PARENT;
    }

    public function getFamilyClaimToken(): ?string { return $this->familyClaimToken; }

    public function setFamilyClaimToken(?string $familyClaimToken): static
    {
        $this->familyClaimToken = $familyClaimToken;
        return $this;
    }

    public function getFamilyClaimExpiresAt(): ?\DateTimeImmutable { return $this->familyClaimExpiresAt; }

    public function getFamilyClaimRevokedAt(): ?\DateTimeImmutable { return $this->familyClaimRevokedAt; }

    public function issueFamilyClaim(): static
    {
        if (!$this->isManaged()) {
            throw new \DomainException('Доступ можно выдать только managed-профилю');
        }
        $this->familyClaimToken = bin2hex(random_bytes(32));
        $this->familyClaimExpiresAt = new \DateTimeImmutable('+7 days');
        $this->familyClaimRevokedAt = null;

        return $this;
    }

    public function revokeFamilyClaim(): static
    {
        if ($this->claimedAt !== null) {
            throw new \DomainException('Аккаунт ребёнка уже активирован');
        }
        $this->familyClaimRevokedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function isFamilyClaimUsable(): bool
    {
        return $this->familyClaimToken !== null
            && $this->claimedAt === null
            && $this->familyClaimRevokedAt === null
            && $this->familyClaimExpiresAt !== null
            && $this->familyClaimExpiresAt > new \DateTimeImmutable();
    }

    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }

    public function setClaimedAt(?\DateTimeImmutable $claimedAt): static
    {
        $this->claimedAt = $claimedAt;
        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable { return $this->birthDate; }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;
        return $this;
    }

    public function getGender(): ?string { return $this->gender; }

    public function setGender(?string $gender): static
    {
        $this->gender = $gender;
        return $this;
    }

    public function getHeightCm(): ?int { return $this->heightCm; }

    public function setHeightCm(?int $heightCm): static
    {
        $this->heightCm = $heightCm;
        return $this;
    }

    public function getClothingSize(): ?string { return $this->clothingSize; }

    public function setClothingSize(?string $clothingSize): static
    {
        $this->clothingSize = $clothingSize;
        return $this;
    }

    public function getShoeSize(): ?string { return $this->shoeSize; }

    public function setShoeSize(?string $shoeSize): static
    {
        $this->shoeSize = $shoeSize;
        return $this;
    }

    public function getProfileNotes(): ?string { return $this->profileNotes; }

    public function setProfileNotes(?string $profileNotes): static
    {
        $this->profileNotes = $profileNotes;
        return $this;
    }

    public function getProfileCompletedAt(): ?\DateTimeImmutable { return $this->profileCompletedAt; }

    public function completeProfile(): static
    {
        $this->profileCompletedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Managed-аккаунт: ребёнок, заведённый родителем без своего email/пароля
     * (синтетический email child-<familyId>-<hex>@family.wearbase.local, случайный пароль).
     *
     * Инварианты:
     * - familyClaimToken выставляется ТОЛЬКО при createChild() и обнуляется при claim —
     *   у всех обычных (существующих) пользователей он NULL → isManaged() = false;
     * - claimed_at у обычных пользователей тоже NULL, поэтому по нему одному судить нельзя —
     *   поэтому признак: живой claim-токен ЛИБО синтетический домен email (страховка на случай,
     *   если токен обнулили вручную, не выдав настоящий email).
     */
    public function isManaged(): bool
    {
        return $this->claimedAt === null
            && ($this->familyClaimToken !== null
                || str_ends_with((string) $this->email, '@' . self::MANAGED_EMAIL_DOMAIN));
    }

    public function isBrandManager(): bool
    {
        return in_array('ROLE_BRAND_MANAGER', $this->getRoles(), true)
            || in_array('ROLE_BRAND_OWNER', $this->getRoles(), true);
    }
}
