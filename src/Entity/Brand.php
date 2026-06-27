<?php

namespace App\Entity;

use App\Repository\BrandRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Nevinny\AdminCoreBundle\Entity\Trait\Owner;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\UniqueConstraint(
    name: "slug_unique_idx",
    columns: ["slug"]
)]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
#[UniqueEntity(fields: ["slug"], message: "Этот slug уже используется")]
class Brand
{
    use Created, Owner, Status;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[Vich\UploadableField(mapping: 'brand_logo', fileNameProperty: 'logo')]
    private ?File $logoFile = null;

    /** Логотип закреплён оператором вручную → агент-пуш его не перезаписывает. */
    #[ORM\Column(options: ['default' => false])]
    private bool $logoLocked = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anons = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(nullable: true)]
    private ?int $parent = null;

    #[ORM\Column(options: ['default' => '0'])]
    private ?int $ord = 0;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'brand')]
    private Collection $products;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $city = null;

    /**
     * @var Collection<int, BrandSize>
     */
    #[ORM\JoinTable(name: 'brand_size_brand')]
    #[ORM\ManyToMany(targetEntity: BrandSize::class, inversedBy: 'brands')]
    private Collection $sizes;

    /**
     * @var Collection<int, BrandStyle>
     */
    #[ORM\ManyToMany(targetEntity: BrandStyle::class, mappedBy: 'brands')]
    private Collection $styles;

    /**
     * @var Collection<int, BrandAudience>
     */
    #[ORM\ManyToMany(targetEntity: BrandAudience::class, mappedBy: 'brands')]
    private Collection $audiences;

    /**
     * @var Collection<int, BrandTier>
     */
    #[ORM\ManyToMany(targetEntity: BrandTier::class, mappedBy: 'brands')]
    private Collection $tiers;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $foundingYear = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $country = null;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\JoinTable(name: 'brand_category')]
    #[ORM\ManyToMany(targetEntity: Category::class, inversedBy: 'brands')]
    private Collection $categories;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $firstLetter = null;

    #[ORM\Column(length: 5, options: ['default' => 'ru'])]
    private string $locale = 'ru';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaKeywords = null;

    /**
     * @var Collection<int, BrandLink>
     */
    #[ORM\OneToMany(targetEntity: BrandLink::class, mappedBy: 'brand', cascade: ['persist'], orphanRemoval: true)]
    private Collection $links;

    /**
     * @var Collection<int, BrandImage>
     */
    #[ORM\OneToMany(targetEntity: BrandImage::class, mappedBy: 'brand', orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $images;

    /**
     * @var Collection<int, Subscription>
     */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'brand')]
    private Collection $subscriptions;

    /**
     * @var Collection<int, BrandStore>
     */
    #[ORM\OneToMany(targetEntity: BrandStore::class, mappedBy: 'brand', cascade: ['persist'], orphanRemoval: true)]
    private Collection $stores;

    // ---- Contact enrichment tracking ----

    /** Когда последний раз запускалось обогащение (NULL = ещё не запускалось) */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $contactEnrichedAt = null;

    /**
     * Результат последнего обогащения:
     * 'enriched'  — найден хотя бы сайт или email
     * 'partial'   — найдено что-то, но неполно
     * 'not_found' — ничего не нашлось (терминальный статус, не повторяем)
     * 'error'     — ошибка запроса (можно повторить до 3 раз)
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contactStatus = null;

    /** Количество попыток обогащения (для ограничения ретраев по статусу 'error') */
    #[ORM\Column(options: ['default' => 0])]
    private int $contactAttempts = 0;

    /** Денормализованный счётчик исходящих кликов на ссылки бренда (через /go/{id}); сортировка по популярности. */
    #[ORM\Column(options: ['default' => 0])]
    private int $outboundClickCount = 0;

    // ---- Дрип-публикация (прод) ----

    /** В очереди на дрип-публикацию (status='new' + publish_pending=1 → publish-tick активирует). */
    #[ORM\Column(options: ['default' => false])]
    private bool $publishPending = false;

    /** Когда бренд опубликован дрип-кроном (аудит темпа ramp-up). */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    // ---- Жизненный цикл: ниша + закрытие ----

    /**
     * Вердикт классификатора ниши (app:brand:niche-check):
     * NULL — не проверен; 'in' — в нише (одежда+обувь+аксессуары+мода+косметика/уход/парфюм);
     * 'off' — чужая ниша (аптека, техника, авто, продукты, мебель, гигиена рта, БАД).
     * 'off' гейтит конвейер (PipelineQueueRepository) и дрип-публикацию (publish-tick).
     */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $nicheStatus = null;

    /** Короткое обоснование вердикта (для ручного ревью): 'marker:пылесос' или фраза от LLM. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nicheReason = null;

    /** Когда классификатор последний раз выносил вердикт (NULL = ещё не проверялся). */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nicheCheckedAt = null;

    /**
     * Бренд прекратил работу (tombstone). NULL = действующий. Если задан у active-бренда —
     * страница остаётся 200/индексируется, но показывает плашку «закрылся» + альтернативы.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $closedAt = null;

    /** Sequence-версия доставки в агент-API: прод пропускает payload с версией ≤ текущей.
     *  ⚠️ Это НЕ версионирование контента (для истории текста см. BrandContentRevision). */
    #[ORM\Column(options: ['default' => 0])]
    private int $agentSyncVersion = 0;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->sizes = new ArrayCollection();
        $this->styles = new ArrayCollection();
        $this->audiences = new ArrayCollection();
        $this->tiers = new ArrayCollection();
        $this->links = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
        $this->stores = new ArrayCollection();
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->title ?? 'Без названия';
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        $this->updateFirstLetter();

        return $this;
    }

    public function getParent(): ?int
    {
        return $this->parent;
    }

    public function setParent(int $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getOrd(): ?int
    {
        return $this->ord;
    }

    public function setOrd(int $ord): static
    {
        $this->ord = $ord;

        return $this;
    }

    private function updateFirstLetter(): void
    {
        if (!$this->getTitle()) {
            $this->firstLetter = null;
            return;
        }

        $firstChar = mb_substr($this->getTitle(), 0, 1);
        $this->firstLetter = $this->normalizeFirstLetter($firstChar);
    }

    private function normalizeFirstLetter(string $letter): string
    {
        $upperLetter = mb_strtoupper($letter, 'UTF-8');

//        // Обработка специальных символов
//        if (is_numeric($letter)) {
//            return '0-9';
//        }
//
//        if (!preg_match('/[\p{L}\p{N}]/u', $letter)) {
//            return '#';
//        }

        return $upperLetter;
    }


    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function isLogoLocked(): bool
    {
        return $this->logoLocked;
    }

    public function setLogoLocked(bool $locked): static
    {
        $this->logoLocked = $locked;

        return $this;
    }

    public function getLogoFile(): ?File
    {
        return $this->logoFile;
    }

    public function setLogoFile(?File $file = null): void
    {
        $this->logoFile = $file;

        if ($file !== null) {
            $this->updated_at = new \DateTime();
        }
    }

    public function getAnons(): ?string
    {
        return $this->anons;
    }

    public function setAnons(?string $anons): static
    {
        $this->anons = $anons;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setBrand($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            // set the owning side to null (unless already changed)
            if ($product->getBrand() === $this) {
                $product->setBrand(null);
            }
        }

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    /**
     * @return Collection<int, BrandSize>
     */
    public function getSizes(): Collection
    {
        return $this->sizes;
    }
    public function setSizes(Collection $sizes): static
    {
        $this->sizes = $sizes;

        return $this;
    }

    public function addBrandSize(BrandSize $brandSize): static
    {
        if (!$this->sizes->contains($brandSize)) {
            $this->sizes->add($brandSize);
            $brandSize->addBrand($this);
        }

        return $this;
    }

    public function removeBrandSize(BrandSize $brandSize): static
    {
        if ($this->sizes->removeElement($brandSize)) {
            $brandSize->removeBrand($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandStyle>
     */
    public function getStyles(): Collection
    {
        return $this->styles;
    }

    public function addStyle(BrandStyle $style): static
    {
        if (!$this->styles->contains($style)) {
            $this->styles->add($style);
            $style->addBrand($this);
        }

        return $this;
    }

    public function removeStyle(BrandStyle $style): static
    {
        if ($this->styles->removeElement($style)) {
            $style->removeBrand($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandAudience>
     */
    public function getAudiences(): Collection
    {
        return $this->audiences;
    }

    public function addAudience(BrandAudience $audience): static
    {
        if (!$this->audiences->contains($audience)) {
            $this->audiences->add($audience);
            $audience->addBrand($this);
        }

        return $this;
    }

    public function removeAudience(BrandAudience $audience): static
    {
        if ($this->audiences->removeElement($audience)) {
            $audience->removeBrand($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandTier>
     */
    public function getTiers(): Collection
    {
        return $this->tiers;
    }

    public function addTier(BrandTier $tier): static
    {
        if (!$this->tiers->contains($tier)) {
            $this->tiers->add($tier);
            $tier->addBrand($this);
        }

        return $this;
    }

    public function removeTier(BrandTier $tier): static
    {
        if ($this->tiers->removeElement($tier)) {
            $tier->removeBrand($this);
        }

        return $this;
    }

    public function getFoundingYear(): ?string
    {
        return $this->foundingYear;
    }

    public function setFoundingYear(?string $foundingYear): static
    {
        $this->foundingYear = $foundingYear;
        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        $this->tagline = $tagline;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->addBrand($this);
        }
        return $this;
    }

    public function removeCategory(Category $category): static
    {
        if ($this->categories->removeElement($category)) {
            $category->removeBrand($this);
        }
        return $this;
    }

    public function getFirstLetter(): ?string
    {
        if(empty($this->firstLetter))
        {
            $this->updateFirstLetter();
        }
        return $this->firstLetter;
    }

    public function setFirstLetter(?string $firstLetter): static
    {
        $this->firstLetter = $firstLetter;

        return $this;
    }

    /**
     * @return Collection<int, BrandLink>
     */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function addLink(BrandLink $link): static
    {
        if (!$this->links->contains($link)) {
            $this->links->add($link);
            $link->setBrand($this);
        }

        return $this;
    }

    public function removeLink(BrandLink $link): static
    {
        if ($this->links->removeElement($link)) {
            // set the owning side to null (unless already changed)
            if ($link->getBrand() === $this) {
                $link->setBrand(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandImage>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(BrandImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setBrand($this);
        }

        return $this;
    }

    public function removeImage(BrandImage $image): static
    {
        if ($this->images->removeElement($image)) {
            // set the owning side to null (unless already changed)
            if ($image->getBrand() === $this) {
                $image->setBrand(null);
            }
        }

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getMetaKeywords(): ?string
    {
        return $this->metaKeywords;
    }

    public function setMetaKeywords(?string $metaKeywords): static
    {
        $this->metaKeywords = $metaKeywords;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function getActiveSubscription(): ?Subscription
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->isActive()) {
                return $subscription;
            }
        }
        return null;
    }

    // ---- BrandStore ----

    /**
     * @return Collection<int, BrandStore>
     */
    public function getStores(): Collection
    {
        return $this->stores;
    }

    public function addStore(BrandStore $store): static
    {
        if (!$this->stores->contains($store)) {
            $this->stores->add($store);
            $store->setBrand($this);
        }

        return $this;
    }

    public function removeStore(BrandStore $store): static
    {
        if ($this->stores->removeElement($store)) {
            if ($store->getBrand() === $this) {
                $store->setBrand(null);
            }
        }

        return $this;
    }

    // ---- Contact enrichment ----

    public function getContactEnrichedAt(): ?\DateTimeInterface
    {
        return $this->contactEnrichedAt;
    }

    public function setContactEnrichedAt(?\DateTimeInterface $contactEnrichedAt): static
    {
        $this->contactEnrichedAt = $contactEnrichedAt;

        return $this;
    }

    public function getContactStatus(): ?string
    {
        return $this->contactStatus;
    }

    public function setContactStatus(?string $contactStatus): static
    {
        $this->contactStatus = $contactStatus;

        return $this;
    }

    public function getContactAttempts(): int
    {
        return $this->contactAttempts;
    }

    public function setContactAttempts(int $contactAttempts): static
    {
        $this->contactAttempts = $contactAttempts;

        return $this;
    }

    public function getOutboundClickCount(): int
    {
        return $this->outboundClickCount;
    }

    public function setOutboundClickCount(int $outboundClickCount): static
    {
        $this->outboundClickCount = $outboundClickCount;

        return $this;
    }

    public function isPublishPending(): bool
    {
        return $this->publishPending;
    }

    public function setPublishPending(bool $pending): static
    {
        $this->publishPending = $pending;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $at): static
    {
        $this->publishedAt = $at;

        return $this;
    }

    // ─── Машина состояний бренда: доменные переходы статуса ───────────────────
    // Инвариант перехода держим здесь (по образцу BrandRagPipeline::markEmbedded —
    // «setStatus() напрямую размазывал машину состояний, инварианты не держались»).
    // Побочные эффекты (граф перелинковки, прод-синк, IndexNow) — на стороне
    // вызывающих: они контекстно-зависимы (fail-open weave в дрипе vs транзакция
    // в агент-ingest), и засовывать их в общий метод протекло бы абстракцией.

    /** В очередь дрип-публикации: → new (создание/возврат в очередь). */
    public function queue(): static
    {
        $this->setStatus(Statuses::New);
        $this->publishPending = true;

        return $this;
    }

    /**
     * Опубликовать: new|disabled → active. Идемпотентно (повтор на active — no-op).
     *
     * @param \DateTimeInterface|null $at время публикации; по умолчанию — сейчас по МСК
     *        (publish-tick считает published_today по нему — иначе на UTC-проде счёт съезжает на 3ч).
     * @return bool true, если статус реально сменился
     * @throws \DomainException из deleted/system публиковать нельзя (нужно явное восстановление)
     */
    public function publish(?\DateTimeInterface $at = null): bool
    {
        $status = $this->getStatus();
        if ($status === Statuses::Active) {
            return false;
        }
        if (!in_array($status, [Statuses::New, Statuses::Disabled], true)) {
            throw new \DomainException(sprintf('Нельзя опубликовать бренд из статуса "%s"', $status?->value ?? 'null'));
        }
        $this->setStatus(Statuses::Active);
        $this->publishPending = false;
        $this->publishedAt = $at ?? new \DateTime('now', new \DateTimeZone('Europe/Moscow'));

        return true;
    }

    /** Снять с публикации: → disabled. Идемпотентно (повтор на disabled — no-op). */
    public function unpublish(): bool
    {
        if ($this->getStatus() === Statuses::Disabled) {
            return false;
        }
        $this->setStatus(Statuses::Disabled);
        $this->publishPending = false;

        return true;
    }

    /** Мягкое удаление: → deleted (политика проекта — без физического DELETE). */
    public function softDelete(): static
    {
        $this->setStatus(Statuses::Deleted);
        $this->publishPending = false;

        return $this;
    }

    // ─── Ниша (вердикт классификатора) ────────────────────────────────────────

    public function getNicheStatus(): ?string
    {
        return $this->nicheStatus;
    }

    public function getNicheReason(): ?string
    {
        return $this->nicheReason;
    }

    public function getNicheCheckedAt(): ?\DateTimeInterface
    {
        return $this->nicheCheckedAt;
    }

    /** Подтверждённо вне ниши WEARBASE (гейтит конвейер и публикацию). */
    public function isOffNiche(): bool
    {
        return $this->nicheStatus === 'off';
    }

    /** Зафиксировать вердикт классификатора: 'in' | 'off' + обоснование. */
    public function markNiche(string $verdict, ?string $reason, \DateTimeInterface $at): static
    {
        $this->nicheStatus    = $verdict;
        $this->nicheReason    = $reason !== null ? mb_substr($reason, 0, 255) : null;
        $this->nicheCheckedAt = $at;

        return $this;
    }

    // ─── Закрытие бренда (tombstone) ──────────────────────────────────────────

    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }

    /** Бренд прекратил работу (страница-надгробие). */
    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }

    /** Пометить закрывшимся: страница остаётся 200, но с плашкой «закрылся». */
    public function close(?\DateTimeInterface $at = null): static
    {
        $this->closedAt = $at ?? new \DateTime('now', new \DateTimeZone('Europe/Moscow'));

        return $this;
    }

    /** Снять пометку закрытия (бренд возобновил работу). */
    public function reopen(): static
    {
        $this->closedAt = null;

        return $this;
    }

    /**
     * Магазины без soft-deleted (правило проекта: DELETE от пользователя только мягкий).
     * @return Collection<int, BrandStore>
     */
    public function getActiveStores(): Collection
    {
        return $this->stores->filter(
            static fn(BrandStore $s) => $s->getStatus() !== \Nevinny\AdminCoreBundle\Enum\Statuses::Deleted
        );
    }

    public function getAgentSyncVersion(): int
    {
        return $this->agentSyncVersion;
    }

    public function setAgentSyncVersion(int $version): static
    {
        $this->agentSyncVersion = $version;

        return $this;
    }
}
