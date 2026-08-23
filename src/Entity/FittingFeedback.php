<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FittingFeedbackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FittingFeedbackRepository::class)]
#[ORM\Table(name: 'fitting_feedback')]
class FittingFeedback
{
    public const OUTCOME_BOUGHT = 'bought';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_DIFFERENT_SIZE = 'different_size';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOMES = [self::OUTCOME_BOUGHT, self::OUTCOME_REFUSED, self::OUTCOME_DIFFERENT_SIZE, self::OUTCOME_PENDING];

    public const SIZING_SMALL = 'small';
    public const SIZING_TRUE = 'true_to_size';
    public const SIZING_LARGE = 'large';
    public const SIZINGS = [self::SIZING_SMALL, self::SIZING_TRUE, self::SIZING_LARGE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'fittingFeedback')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequestItem $item = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(length: 20)]
    private string $outcome;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $triedSize;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sizing;

    #[ORM\Column(type: Types::JSON)]
    private array $fitIssues;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param string[] $fitIssues */
    public function __construct(User $actor, string $outcome, ?string $triedSize = null, ?string $sizing = null, array $fitIssues = [], ?string $comment = null)
    {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Недопустимый результат примерки');
        }
        if ($sizing !== null && !in_array($sizing, self::SIZINGS, true)) {
            throw new \InvalidArgumentException('Недопустимая оценка размерности');
        }
        $triedSize = trim((string) $triedSize);
        $comment = trim((string) $comment);
        if (mb_strlen($triedSize) > 50 || mb_strlen($comment) > 2000 || count($fitIssues) > 10) {
            throw new \InvalidArgumentException('Слишком много данных о примерке');
        }
        $this->actor = $actor;
        $this->outcome = $outcome;
        $this->triedSize = $triedSize !== '' ? $triedSize : null;
        $this->sizing = $sizing;
        $this->fitIssues = array_values(array_unique(array_map('strval', $fitIssues)));
        $this->comment = $comment !== '' ? $comment : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getItem(): ?PurchaseRequestItem { return $this->item; }
    public function setItem(PurchaseRequestItem $item): void { $this->item = $item; }
    public function getActor(): User { return $this->actor; }
    public function getOutcome(): string { return $this->outcome; }
    public function getTriedSize(): ?string { return $this->triedSize; }
    public function getSizing(): ?string { return $this->sizing; }
    /** @return string[] */
    public function getFitIssues(): array { return $this->fitIssues; }
    public function getComment(): ?string { return $this->comment; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
