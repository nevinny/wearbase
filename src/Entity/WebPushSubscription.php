<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebPushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebPushSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_web_push_endpoint', columns: ['endpoint_hash'])]
class WebPushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    #[ORM\Column(length: 64)]
    private string $endpointHash;

    #[ORM\Column(type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $authToken;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contentEncoding = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authToken, ?string $contentEncoding = null)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($endpoint, $publicKey, $authToken, $contentEncoding);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function getAuthToken(): string { return $this->authToken; }
    public function getContentEncoding(): ?string { return $this->contentEncoding; }
    public function isActive(): bool { return $this->revokedAt === null; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }

    public function update(string $endpoint, string $publicKey, string $authToken, ?string $contentEncoding = null): void
    {
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->contentEncoding = $contentEncoding;
        $this->revokedAt = null;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
