<?php

namespace App\Entity;

use App\Repository\SocialPostRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Единица автопостинга — статус-машина (зеркало BrandRagPipeline):
 * planned → generated → qa_ok → scheduled → publishing → published → done
 * (+ held — на ручной просмотр; generate_failed / publish_failed).
 *
 * Очередь публикации клеймится атомарно (claimPending, FOR UPDATE SKIP LOCKED) по статусу
 * scheduled и подошедшему scheduledAt. См. docs/marketing_instagram.md §4.
 */
#[ORM\Entity(repositoryClass: SocialPostRepository::class)]
#[ORM\Table(name: 'social_post')]
#[ORM\Index(name: 'idx_sp_status', columns: ['status'])]
#[ORM\Index(name: 'idx_sp_sched', columns: ['status', 'scheduled_at'])]
#[ORM\Index(name: 'idx_sp_channel', columns: ['channel_id'])]
#[ORM\Index(name: 'idx_sp_brand', columns: ['brand_id'])]
class SocialPost
{
    use Created;

    public const STATUS_PLANNED         = 'planned';
    public const STATUS_GENERATED       = 'generated';
    public const STATUS_QA_OK           = 'qa_ok';
    public const STATUS_SCHEDULED       = 'scheduled';
    public const STATUS_PUBLISHING      = 'publishing';
    public const STATUS_PUBLISHED       = 'published';
    public const STATUS_DONE            = 'done';
    /** Требует ручного просмотра: Reels/UGC-рубрики, провал QA, нет медиа. НЕ публикуем автоматически. */
    public const STATUS_HELD            = 'held';
    public const STATUS_GENERATE_FAILED = 'generate_failed';
    public const STATUS_PUBLISH_FAILED  = 'publish_failed';

    public const MEDIA_IMAGE    = 'image';
    public const MEDIA_CAROUSEL = 'carousel';
    public const MEDIA_REELS    = 'reels';
    public const MEDIA_NONE     = 'none';

    /** Ветки A/B: логотип бренда первым слайдом vs последним. */
    public const VARIANT_LOGO_FIRST = 'logo_first';
    public const VARIANT_LOGO_LAST  = 'logo_last';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SocialChannel $channel = null;

    /** Бренд-герой поста (если рубрика брендовая); null для манифеста/калькулятора. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Brand $brand = null;

    /** Рубрика из контент-плана (calculator|new_drops|manifesto|vs_marketplace|brand_week|...). */
    #[ORM\Column(length: 40, options: ['default' => ''])]
    private string $rubric = '';

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PLANNED])]
    private string $status = self::STATUS_PLANNED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $caption = null;

    /** Промпт генерации изображения (адаптивный, из caption через LLM) — храним для разбора/улучшения. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $imagePrompt = null;

    #[ORM\Column(length: 20, options: ['default' => self::MEDIA_NONE])]
    private string $mediaType = self::MEDIA_NONE;

    /**
     * Путь к отрендеренному медиа (картинка/видео). Для карусели — несколько путей,
     * по одному на строку (см. getMediaPaths()/setMediaPaths()).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mediaPath = null;

    /**
     * Обложка Reels (cover_url контейнера). Без неё IG берёт первый кадр клипа, а он зависит
     * от ветки A/B — обложка обязана быть одинаковой, иначе эксперимент сравнивает и её.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverPath = null;

    /**
     * Ветка A/B-эксперимента (VARIANT_*), null — пост вне эксперимента.
     * Группировка результатов — app:social:evaluate по (рубрика, вариант).
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $variant = null;

    /**
     * Реализованная ступень лестницы хуков + источник битов (SlideScriptComposer), напр.
     * 'h2.city|b.rag2|c.save'. Пишется только для галерей/Reels (media=carousel|reels) —
     * по нему CaptionGenerator строит первую строку подписи, а app:social:evaluate группирует
     * closed-loop.
     */
    #[ORM\Column(length: 48, nullable: true)]
    private ?string $scriptKey = null;

    /** Сериализованный SlideScript (JSON) — переиспользуется между каруселью и Reels ОДНОГО
     *  бренда (SocialGenerateCommand ищет последний пост бренда с непустым script_json): LLM
     *  недетерминирован, повторный вызов дал бы другой текст. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $scriptJson = null;

    /** Число кадров сценария — нужно app:social:evaluate для watch_ratio Reels. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $slideCount = null;

    /** CTA-ссылка (с UTM) — вынесена из подписи, публикаторы оформляют по-своему. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ctaUrl = null;

    /** Текст-подпись CTA-ссылки (для кликабельного текста в TG). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ctaLabel = null;

    /** Сгенерировано ИИ (для обязательной маркировки на площадке). */
    #[ORM\Column(options: ['default' => false])]
    private bool $aiGenerated = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    /** ID опубликованного поста на площадке (для подтягивания метрик). */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $externalId = null;

    /** Клейм очереди публикации (как brand_source_url.claimed_at). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $claimedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $generateAttempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $publishAttempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): ?SocialChannel
    {
        return $this->channel;
    }

    public function setChannel(?SocialChannel $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): self
    {
        $this->brand = $brand;
        return $this;
    }

    public function getRubric(): string
    {
        return $this->rubric;
    }

    public function setRubric(string $rubric): self
    {
        $this->rubric = $rubric;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): self
    {
        $this->caption = $caption;
        return $this;
    }

    public function getImagePrompt(): ?string
    {
        return $this->imagePrompt;
    }

    public function setImagePrompt(?string $imagePrompt): self
    {
        $this->imagePrompt = $imagePrompt;
        return $this;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function setMediaType(string $mediaType): self
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getMediaPath(): ?string
    {
        return $this->mediaPath;
    }

    public function setMediaPath(?string $mediaPath): self
    {
        $this->mediaPath = $mediaPath;
        return $this;
    }

    /**
     * Все медиа поста по порядку: одиночная картинка → один элемент, карусель → N слайдов.
     *
     * @return list<string>
     */
    public function getMediaPaths(): array
    {
        if ($this->mediaPath === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $this->mediaPath)),
            static fn (string $path) => $path !== '',
        ));
    }

    public function getCoverPath(): ?string
    {
        return $this->coverPath;
    }

    public function setCoverPath(?string $coverPath): self
    {
        $this->coverPath = $coverPath;
        return $this;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    public function setVariant(?string $variant): self
    {
        $this->variant = $variant;
        return $this;
    }

    public function getScriptKey(): ?string
    {
        return $this->scriptKey;
    }

    public function setScriptKey(?string $scriptKey): self
    {
        $this->scriptKey = $scriptKey;
        return $this;
    }

    public function getScriptJson(): ?string
    {
        return $this->scriptJson;
    }

    public function setScriptJson(?string $scriptJson): self
    {
        $this->scriptJson = $scriptJson;
        return $this;
    }

    public function getSlideCount(): ?int
    {
        return $this->slideCount;
    }

    public function setSlideCount(?int $slideCount): self
    {
        $this->slideCount = $slideCount;
        return $this;
    }

    /** @param list<string> $paths слайды карусели по порядку */
    public function setMediaPaths(array $paths): self
    {
        $clean = array_values(array_filter(array_map('trim', $paths), static fn (string $p) => $p !== ''));
        $this->mediaPath = $clean === [] ? null : implode("\n", $clean);

        return $this;
    }

    public function getCtaUrl(): ?string
    {
        return $this->ctaUrl;
    }

    public function setCtaUrl(?string $ctaUrl): self
    {
        $this->ctaUrl = $ctaUrl;
        return $this;
    }

    public function getCtaLabel(): ?string
    {
        return $this->ctaLabel;
    }

    public function setCtaLabel(?string $ctaLabel): self
    {
        $this->ctaLabel = $ctaLabel;
        return $this;
    }

    public function isAiGenerated(): bool
    {
        return $this->aiGenerated;
    }

    public function setAiGenerated(bool $aiGenerated): self
    {
        $this->aiGenerated = $aiGenerated;
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeInterface $at): self
    {
        $this->scheduledAt = $at;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $at): self
    {
        $this->publishedAt = $at;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getClaimedAt(): ?\DateTimeInterface
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeInterface $at): self
    {
        $this->claimedAt = $at;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getGenerateAttempts(): int
    {
        return $this->generateAttempts;
    }

    public function setGenerateAttempts(int $n): self
    {
        $this->generateAttempts = $n;
        return $this;
    }

    public function getPublishAttempts(): int
    {
        return $this->publishAttempts;
    }

    public function setPublishAttempts(int $n): self
    {
        $this->publishAttempts = $n;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): self
    {
        $this->lastError = $error;
        return $this;
    }
}
