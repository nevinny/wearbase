<?php

declare(strict_types=1);

namespace App\Service\Family;

use App\Entity\User;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeNeed;
use App\Repository\WardrobeCategoryRepository;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeNeedRepository;

final class FamilyWardrobeMatrix
{
    public const GROUPS = [
        'tops' => 'Верх', 'bottoms' => 'Низ', 'footwear' => 'Обувь',
        'headwear' => 'Шапки и шарфы', 'onepiece' => 'Цельные вещи', 'other' => 'Другие вещи',
    ];

    public function __construct(
        private readonly WardrobeNeedService $needsService,
        private readonly WardrobeItemRepository $items,
        private readonly WardrobeCategoryRepository $categories,
        private readonly WardrobeNeedRepository $needs,
    ) {}

    public function overview(User $actor, string $season = ''): array
    {
        if ($season !== '' && !isset(WardrobeNeed::SEASONS[$season])) {
            throw new \InvalidArgumentException('Неизвестный сезон');
        }
        $children = $this->needsService->subjectsFor($actor);
        $categories = $this->categories->findActiveTree();
        $categoryCodes = [];
        foreach ($categories as $category) {
            $categoryCodes[$category->getCode()] = $category;
        }
        $seasons = array_diff_key(WardrobeNeed::SEASONS, ['all' => true]);
        if ($season !== '') {
            $seasons = [$season => WardrobeNeed::SEASONS[$season]];
        }
        $sections = [];
        foreach ($seasons as $code => $label) {
            $sections[$code] = ['label' => $label, 'groups' => []];
        }
        $uncategorized = [];
        $totals = array_fill_keys(array_map(static fn (User $child): int => $child->getId(), $children), 0);
        foreach ($this->items->findForFamilyMatrix($children) as $item) {
            ++$totals[$item->getUser()->getId()];
            $category = $item->getCategoryRef() ?? $this->categories->resolveActive((string) $item->getCategory(), $categories);
            if ($category === null || !isset(WardrobeNeed::SEASONS[$item->getSeason() ?? ''])) {
                $uncategorized[] = $item;
                continue;
            }
            foreach ($sections as $code => &$section) {
                if ($item->getSeason() === 'all' || $item->getSeason() === $code) {
                    $this->addToCell($section['groups'], $category, $item->getUser(), 'items', $item);
                }
            }
            unset($section);
        }
        $openNeeds = [];
        $closedNeeds = [];
        foreach ($this->needs->findVisibleTo($actor, $children) as $need) {
            if ($season !== '' && $need->getSeason() !== 'all' && $need->getSeason() !== $season) {
                continue;
            }
            if (!$need->isOpen()) {
                $closedNeeds[] = $need;
                continue;
            }
            $openNeeds[] = $need;
            foreach ($sections as $code => &$section) {
                if ($need->getSeason() === 'all' || $need->getSeason() === $code) {
                    $this->addToCell($section['groups'], $need->getCategory(), $need->getSubject(), 'needs', $need);
                }
            }
            unset($section);
        }
        foreach ($sections as &$section) {
            // Keep the main areas visible even before clothes have been entered.
            foreach (['tops', 'outerwear', 'bottoms', 'footwear', 'hat', 'scarf'] as $code) {
                $category = $categoryCodes[$code] ?? null;
                if ($category === null) {
                    continue;
                }
                $group = $this->groupFor($category);
                $branchPresent = false;
                foreach ($section['groups'][$group] ?? [] as $row) {
                    if ($this->belongsTo($row['category'], $code)) {
                        $branchPresent = true;
                        break;
                    }
                }
                if (!$branchPresent) {
                    $section['groups'][$group][$category->getId()] = ['category' => $category, 'cells' => []];
                }
            }
            foreach ($section['groups'] as &$rows) {
                uasort($rows, static fn (array $a, array $b): int => $a['category']->getSortOrder() <=> $b['category']->getSortOrder()
                    ?: strcmp($a['category']->getName(), $b['category']->getName()));
            }
            unset($rows);
            $section['groups'] = array_replace(array_fill_keys(array_keys(self::GROUPS), []), $section['groups']);
        }
        unset($section);

        return compact('children', 'sections', 'uncategorized', 'totals', 'openNeeds', 'closedNeeds', 'categories');
    }

    private function addToCell(array &$groups, WardrobeCategory $category, User $child, string $field, WardrobeItem|WardrobeNeed $record): void
    {
        $group = $this->groupFor($category);
        $groups[$group][$category->getId()] ??= ['category' => $category, 'cells' => []];
        $groups[$group][$category->getId()]['cells'][$child->getId()][$field][] = $record;
    }

    private function groupFor(WardrobeCategory $category): string
    {
        for ($current = $category, $depth = 0; $current !== null && $depth < 20; $current = $current->getParent(), ++$depth) {
            $group = match ($current->getCode()) {
                'hat', 'scarf' => 'headwear',
                'tops', 'outerwear' => 'tops',
                'bottoms' => 'bottoms',
                'footwear' => 'footwear',
                'dresses', 'jumpsuit' => 'onepiece',
                default => null,
            };
            if ($group !== null) {
                return $group;
            }
        }
        return 'other';
    }

    private function belongsTo(WardrobeCategory $category, string $code): bool
    {
        for ($current = $category, $depth = 0; $current !== null && $depth < 20; $current = $current->getParent(), ++$depth) {
            if ($current->getCode() === $code) {
                return true;
            }
        }
        return false;
    }
}
