<?php

namespace App\Service;

use App\Entity\Brand;
use App\Entity\BrandDatapoint;
use App\Entity\BrandDatapointVote;
use App\Entity\BrandLink;
use App\Entity\BrandStore;
use App\Repository\BrandDatapointRepository;
use App\Repository\BrandDatapointVoteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Краудсорс-валидация: применяет голос посетителя к data-point'у и пересчитывает
 * состояние (MVP — режим «unclaimed», агрессивные пороги; режимы claimed/abandoned
 * — полная версия, см. tasktracker).
 *
 * Переходы (по СУММЕ весов голосов):
 *   reject ≥3            → doubtful (бейдж, но показываем)
 *   reject ≥5 и >2×confirm → hidden (скрыт + очередь ре-обогащения)
 *   confirm ≥5 и reject=0  → pinned (provenance=crowd_confirmed)
 */
class DatapointVoteService
{
    private const TO_DOUBTFUL      = 3;
    private const TO_HIDDEN        = 5;
    private const TO_PINNED        = 5;
    private const HIDDEN_DOMINANCE = 2; // reject > confirm × N

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $appSecret,
    ) {
    }

    /**
     * @return array{state:string, confirm:int, reject:int}
     * @throws \InvalidArgumentException невалидный target/field/vote
     */
    public function applyVote(
        Brand $brand,
        string $targetType,
        ?int $targetId,
        string $field,
        string $vote,
        ?string $suggestion,
        string $clientIp,
        string $userAgent,
        ?int $userId = null,
    ): array {
        if (!in_array($vote, [BrandDatapointVote::VOTE_CONFIRM, BrandDatapointVote::VOTE_REJECT], true)) {
            throw new \InvalidArgumentException('vote: confirm|reject');
        }
        if (!isset(BrandDatapoint::FIELDS[$targetType]) || !in_array($field, BrandDatapoint::FIELDS[$targetType], true)) {
            throw new \InvalidArgumentException('недопустимый target_type/field');
        }
        $this->assertTargetBelongsToBrand($brand, $targetType, $targetId);

        /** @var BrandDatapointRepository $dpRepo */
        $dpRepo = $this->em->getRepository(BrandDatapoint::class);
        $dp = $dpRepo->getOrCreate($brand, $targetType, $targetId, $field);

        // Дедуп: повторный голос с того же отпечатка — upsert (меняет, не плодит).
        $voterHash = $this->voterHash($clientIp, $userAgent);
        /** @var BrandDatapointVoteRepository $voteRepo */
        $voteRepo = $this->em->getRepository(BrandDatapointVote::class);
        $existing = $dp->getId() !== null ? $voteRepo->findByVoter($dp, $voterHash) : null;

        $row = $existing ?? (new BrandDatapointVote())->setDatapoint($dp)->setVoterHash($voterHash);
        $row->setVote($vote)
            ->setUserId($userId)
            ->setWeight($userId !== null ? BrandDatapointVote::WEIGHT_USER : BrandDatapointVote::WEIGHT_ANON);
        if ($suggestion !== null && trim($suggestion) !== '') {
            $row->setSuggestion(mb_substr(trim($suggestion), 0, 500));
        }
        if ($existing === null) {
            $this->em->persist($row);
        }
        $this->em->flush(); // dp.id нужен для sumWeights

        $sums = $voteRepo->sumWeights($dp);
        $dp->setConfirmCount($sums['confirm'])
            ->setRejectCount($sums['reject'])
            ->setRejectWindow($sums['reject']); // MVP: окно = all-time; крон-пересчёт — полная версия

        $this->transition($dp, $sums['confirm'], $sums['reject']);
        $this->em->flush();

        return ['state' => $dp->getState(), 'confirm' => $sums['confirm'], 'reject' => $sums['reject']];
    }

    private function transition(BrandDatapoint $dp, int $confirm, int $reject): void
    {
        // Owner-данные голосами не трогаем (режим claimed — нотификации, полная версия).
        if ($dp->getProvenance() === BrandDatapoint::PROV_OWNER) {
            return;
        }

        if ($reject >= self::TO_HIDDEN && $reject > $confirm * self::HIDDEN_DOMINANCE) {
            if ($dp->getState() !== BrandDatapoint::STATE_HIDDEN) {
                $dp->setState(BrandDatapoint::STATE_HIDDEN)
                    ->setQueuedRevalidateAt(new \DateTime())
                    ->setRevalidatedAt(null);
            }
            return;
        }

        if ($confirm >= self::TO_PINNED && $reject === 0) {
            $dp->setState(BrandDatapoint::STATE_PINNED)
                ->setProvenance(BrandDatapoint::PROV_CROWD_CONFIRMED);
            return;
        }

        if ($reject >= self::TO_DOUBTFUL) {
            $dp->setState(BrandDatapoint::STATE_DOUBTFUL);
            return;
        }

        if ($dp->getState() !== BrandDatapoint::STATE_PINNED) {
            $dp->setState(BrandDatapoint::STATE_ACTIVE);
        }
    }

    /** target_id обязан принадлежать бренду (иначе можно голосовать за чужие строки). */
    private function assertTargetBelongsToBrand(Brand $brand, string $targetType, ?int $targetId): void
    {
        if ($targetType === BrandDatapoint::TYPE_CONTACT) {
            if ($targetId !== null) {
                throw new \InvalidArgumentException('brand_contact не имеет target_id');
            }
            return;
        }
        if ($targetId === null) {
            throw new \InvalidArgumentException('target_id обязателен для link/store/attribute');
        }

        $class = match ($targetType) {
            BrandDatapoint::TYPE_LINK      => BrandLink::class,
            BrandDatapoint::TYPE_STORE     => BrandStore::class,
            BrandDatapoint::TYPE_ATTRIBUTE => \App\Entity\BrandAttribute::class,
            default                        => throw new \InvalidArgumentException('неизвестный target_type'),
        };
        $row = $this->em->find($class, $targetId);
        if ($row === null || $row->getBrand()?->getId() !== $brand->getId()) {
            throw new \InvalidArgumentException('target не принадлежит бренду');
        }
    }

    /** sha256(ip + daily_salt + UA): дедуп без хранения PII; соль ротируется ежедневно. */
    private function voterHash(string $clientIp, string $userAgent): string
    {
        return hash('sha256', $clientIp . '|' . hash_hmac('sha256', date('Y-m-d'), $this->appSecret) . '|' . $userAgent);
    }
}
