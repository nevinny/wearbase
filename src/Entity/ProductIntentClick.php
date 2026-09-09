<?php

namespace App\Entity;

use App\Repository\ProductIntentClickRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only лог сигналов «Хочу купить» на карточке товара бренда, у которого
 * не настроен приём онлайн-оплаты (см. App\Twig\BrandSaleExtension::canSell()).
 * Гость нажимает кнопку вместо «В корзину» — фиксируем спрос, чтобы показать
 * его владельцу (напоминания app:brand:payment-reminders) и в админке.
 */
#[ORM\Entity(repositoryClass: ProductIntentClickRepository::class)]
#[ORM\Table(name: 'product_intent_click')]
#[ORM\Index(name: 'idx_pic_brand_created', columns: ['brand_id', 'created_at'])]
#[ORM\Index(name: 'idx_pic_product', columns: ['product_id'])]
class ProductIntentClick
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'brand_id', nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referer = null;

    /** sha256(User-Agent) — приблизительная уникальность без хранения PII. */
    #[ORM\Column(length: 64, options: ['fixed' => true], nullable: true)]
    private ?string $uaHash = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function setReferer(?string $referer): static
    {
        $this->referer = $referer;

        return $this;
    }

    public function getUaHash(): ?string
    {
        return $this->uaHash;
    }

    public function setUaHash(?string $uaHash): static
    {
        $this->uaHash = $uaHash;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
