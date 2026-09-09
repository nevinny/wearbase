<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeWearEventRepository;
use Doctrine\ORM\EntityManagerInterface;

class WardrobeOutfitLearningService
{
    public function __construct(
        private readonly WardrobeOutfitRepository $outfits,
        private readonly EntityManagerInterface $em,
        private readonly ?WardrobeWearEventRepository $wearEvents = null,
        private readonly ?WardrobeActivationService $activation = null,
        private readonly ?WardrobeConsentRepository $consents = null,
    ) {}

    /**
     * @param array<int, array{title:string,explanation:string,items:WardrobeItem[]}> $suggestions
     * @return array<int, array{title:string,explanation:string,items:WardrobeItem[],feedbackId:int}>
     */
    public function remember(User $user, User $owner, string $prompt, array $suggestions): array
    {
        $entities = [];
        foreach ($suggestions as $index => $suggestion) {
            $outfit = (new WardrobeOutfit())
                ->setUser($user)
                ->setWardrobeOwner($owner)
                ->setPrompt($prompt)
                ->setTitle($suggestion['title'])
                ->setExplanation($suggestion['explanation'])
                ->setItems(array_map([$this, 'snapshot'], $suggestion['items']));
            $this->em->persist($outfit);
            $entities[$index] = $outfit;
        }
        $this->em->flush();
        if ($entities !== []) {
            $this->activation?->firstOutfitCreated($user, $owner);
        }
        foreach ($entities as $index => $outfit) {
            $suggestions[$index]['feedbackId'] = $outfit->getId();
        }

        return $suggestions;
    }

    public function react(User $user, User $wardrobeOwner, int $id, string $reaction): void
    {
        $outfit = $this->outfits->find($id);
        if ($outfit === null
            || $outfit->getUser()->getId() !== $user->getId()
            || $outfit->getWardrobeOwner()->getId() !== $wardrobeOwner->getId()
        ) {
            throw new \DomainException('Образ не найден');
        }
        $outfit->react($reaction);
        $this->em->flush();
    }

    public function context(User $wardrobeOwner): string
    {
        if (!$this->consents?->isPersonalizationGranted($wardrobeOwner)) {
            return '';
        }
        $positive = [];
        $negative = [];
        foreach ($this->outfits->findRecentReacted($wardrobeOwner) as $outfit) {
            $target = $outfit->getReaction() === WardrobeOutfit::REACTION_DISLIKE ? $negative : $positive;
            $weight = $outfit->getReaction() === WardrobeOutfit::REACTION_WORN ? 3 : 1;
            foreach ($outfit->getItems() as $item) {
                foreach (array_filter([$item['category'] ?? null, $item['color'] ?? null, ...($item['styles'] ?? [])]) as $value) {
                    $target[$value] = ($target[$value] ?? 0) + $weight;
                }
            }
            if ($outfit->getReaction() === WardrobeOutfit::REACTION_DISLIKE) {
                $negative = $target;
            } else {
                $positive = $target;
            }
        }
        foreach ($this->wearEvents?->findRecentConfirmed($wardrobeOwner, 50) ?? [] as $event) {
            if (!$event->isConfirmedWorn()) {
                continue;
            }
            $isNegative = $event->getComfort() === 'uncomfortable' || $event->wantsRepeat() === false;
            $target = $isNegative ? $negative : $positive;
            $weight = $event->wantsRepeat() === true ? 4 : 2;
            foreach ($event->getItems() as $eventItem) {
                $item = $eventItem->getItem();
                foreach (array_filter([$item->getCategory(), $item->getColorName()]) as $value) {
                    $target[$value] = ($target[$value] ?? 0) + $weight;
                }
            }
            if ($isNegative) {
                $negative = $target;
            } else {
                $positive = $target;
            }
        }

        arsort($positive);
        arsort($negative);
        $liked = array_slice(array_keys($positive), 0, 8);
        $disliked = array_slice(array_keys($negative), 0, 8);
        if ($liked === [] && $disliked === []) {
            return '';
        }

        return sprintf(
            'Предыдущие реакции пользователя: чаще выбирает [%s]; чаще отклоняет [%s]. Учитывай это, но сохраняй разнообразие.',
            implode(', ', $liked),
            implode(', ', $disliked),
        );
    }

    /** @return array{id:int,category:?string,color:?string,styles:string[]} */
    private function snapshot(WardrobeItem $item): array
    {
        return [
            'id' => (int) $item->getId(),
            'category' => $item->getCategory(),
            'color' => $item->getColorName(),
            'styles' => array_map(static fn ($style): string => $style->getTitle(), $item->getStyles()->toArray()),
        ];
    }
}
