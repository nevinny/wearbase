<?php

namespace App\Entity;

use App\Repository\BrandFaqRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * FAQ-пара бренда (SEO задача C): вопросы — из вопросных/длиннохвостых фраз
 * Wordstat (brand_keyword), ответы — 27b СТРОГО из RAG-фактов + описания бренда
 * («нет факта — пропусти вопрос»). Рендерится аккордеоном + FAQPage JSON-LD
 * на странице бренда. Генерация: app:brand:faq.
 */
#[ORM\Entity(repositoryClass: BrandFaqRepository::class)]
#[ORM\Table(name: 'brand_faq')]
#[ORM\Index(name: 'idx_bfaq_brand', columns: ['brand_id'])]
class BrandFaq
{
    use Created;

    public const SOURCE_WORDSTAT = 'wordstat';
    public const SOURCE_LLM      = 'llm';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 500)]
    private string $question = '';

    #[ORM\Column(type: 'text')]
    private string $answer = '';

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    /** Задел на локализацию; BrandTranslation сейчас не используем. */
    #[ORM\Column(length: 5, options: ['default' => 'ru'])]
    private string $locale = 'ru';

    /** Откуда вопрос: wordstat-фраза или сгенерён LLM. */
    #[ORM\Column(length: 16, options: ['default' => self::SOURCE_WORDSTAT])]
    private string $source = self::SOURCE_WORDSTAT;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): self
    {
        $this->question = $question;
        return $this;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): self
    {
        $this->answer = $answer;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }
}
