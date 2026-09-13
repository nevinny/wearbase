<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeConsentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeConsentRepository::class)]
#[ORM\Table(name: 'wardrobe_consent')]
class WardrobeConsent
{
    public const POLICY_VERSION = '2026-09-13';

    /**
     * Дата, с которой текст согласия (templates/pages/consent.html.twig) описывает
     * передачу фото внешнему сервису обработки. Отметки, проставленные раньше,
     * давались под текстом, где такой передачи не было, — они остаются в силе для
     * обработки на оборудовании Оператора, но не покрывают передачу наружу.
     * Двигать константу только вместе с правкой раздела о фото в тексте согласия.
     */
    public const PHOTO_TRANSFER_DISCLOSED_SINCE = '2026-09-13';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $grantedBy;

    #[ORM\Column(length: 20)]
    private string $policyVersion = self::POLICY_VERSION;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $photoProcessingGrantedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $personalizationGrantedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sharedLearningGrantedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $subject, User $grantedBy)
    {
        $this->subject = $subject;
        $this->grantedBy = $grantedBy;
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): User { return $this->subject; }
    public function getGrantedBy(): User { return $this->grantedBy; }
    public function getPolicyVersion(): string { return $this->policyVersion; }
    public function getPhotoProcessingGrantedAt(): ?\DateTimeImmutable { return $this->photoProcessingGrantedAt; }
    public function isPhotoProcessingGranted(): bool { return $this->photoProcessingGrantedAt !== null && $this->revokedAt === null; }
    public function getPersonalizationGrantedAt(): ?\DateTimeImmutable { return $this->personalizationGrantedAt; }
    public function isPersonalizationGranted(): bool { return $this->personalizationGrantedAt !== null && $this->revokedAt === null; }

    /**
     * Покрывает ли отметка передачу фото внешнему сервису. Смотрим на дату самой
     * отметки, а не на policy_version: версия у строки одна на все три согласия и
     * переписывается при выдаче любого из них (grantPersonalization), то есть по ней
     * старое фото-согласие могло бы «омолодиться» чужой выдачей.
     */
    public function coversExternalPhotoTransfer(): bool
    {
        return $this->isPhotoProcessingGranted()
            && $this->photoProcessingGrantedAt->format('Y-m-d') >= self::PHOTO_TRANSFER_DISCLOSED_SINCE;
    }

    public function grantPhotoProcessing(User $grantor): void
    {
        $this->resetAfterRevocation();
        $this->grantedBy = $grantor;
        $this->policyVersion = self::POLICY_VERSION;
        $this->photoProcessingGrantedAt = new \DateTimeImmutable();
        $this->revokedAt = null;
    }

    public function grantPersonalization(User $grantor): void
    {
        $this->resetAfterRevocation();
        $this->grantedBy = $grantor;
        $this->policyVersion = self::POLICY_VERSION;
        $this->personalizationGrantedAt = new \DateTimeImmutable();
    }

    public function revokePersonalization(): void
    {
        $this->personalizationGrantedAt = null;
    }

    public function revoke(): void { $this->revokedAt = new \DateTimeImmutable(); }

    private function resetAfterRevocation(): void
    {
        if ($this->revokedAt === null) {
            return;
        }
        $this->photoProcessingGrantedAt = null;
        $this->personalizationGrantedAt = null;
        $this->sharedLearningGrantedAt = null;
        $this->revokedAt = null;
    }
}
