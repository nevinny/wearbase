<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/v1/wardrobe-app', name: 'api_wardrobe_app_')]
class WardrobeAppController extends AbstractController
{
    public function __construct(
        private readonly FamilyService $familyService,
        private readonly WardrobeItemRepository $items,
    ) {}

    #[Route('/bootstrap', name: 'bootstrap', methods: ['GET'])]
    public function bootstrap(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->privateJson(['error' => 'authentication_required'], Response::HTTP_UNAUTHORIZED);
        }

        $members = $this->familyService->membersFor($user);

        return $this->privateJson([
            'user' => $this->memberData($user, $user),
            'hasFamily' => $user->getFamily() !== null,
            'members' => array_map(
                fn (User $member): array => $this->memberData($user, $member),
                $members,
            ),
        ]);
    }

    #[Route('/items', name: 'items', methods: ['GET'])]
    public function items(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->privateJson(['error' => 'authentication_required'], Response::HTTP_UNAUTHORIZED);
        }

        $memberId = $this->memberId($request);
        if ($request->query->has('member') && $memberId === null) {
            return $this->privateJson(['error' => 'invalid_member'], Response::HTTP_BAD_REQUEST);
        }

        $limit = max(1, min(100, $request->query->getInt('limit', 24)));
        $cursor = $this->positiveInt($request->query->get('cursor'));
        if ($request->query->has('cursor') && $cursor === null) {
            return $this->privateJson(['error' => 'invalid_cursor'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $member = $this->familyService->resolveMember($user, $memberId);
        } catch (AccessDeniedException) {
            return $this->privateJson(['error' => 'member_forbidden'], Response::HTTP_FORBIDDEN);
        }
        $items = $this->items->findActivePageForUser($member, $cursor, $limit);
        $hasMore = count($items) > $limit;
        if ($hasMore) {
            array_pop($items);
        }

        return $this->privateJson([
            'member' => $this->memberData($user, $member),
            'items' => array_map(
                static fn (WardrobeItem $item): array => [
                    'id' => $item->getId(),
                    'itemNo' => $item->getItemNo(),
                    'name' => $item->getName(),
                    'category' => $item->getCategory(),
                    'brand' => $item->getCustomBrandName(),
                    'color' => $item->getColorName(),
                    'size' => $item->getSize(),
                    'season' => $item->getSeason(),
                    'completionStatus' => $item->getCompletionStatus(),
                    'itemStatus' => $item->getItemStatus(),
                    'wearStatus' => $item->getWearStatus(),
                ],
                $items,
            ),
            'page' => [
                'limit' => $limit,
                'hasMore' => $hasMore,
                'nextCursor' => $hasMore && $items !== [] ? end($items)->getItemNo() : null,
            ],
        ]);
    }

    /** @return array{id: int|null, displayName: string, familyRole: string|null, isSelf: bool, canManage: bool, itemCount?: int} */
    private function memberData(User $actor, User $member): array
    {
        $displayName = trim(($member->getFirstName() ?? '').' '.($member->getLastName() ?? ''));
        $canManage = $this->familyService->canManage($actor, $member);

        $data = [
            'id' => $member->getId(),
            'displayName' => $displayName !== '' ? $displayName : 'Пользователь',
            'familyRole' => $member->getFamilyRole(),
            'isSelf' => $actor->getId() === $member->getId(),
            'canManage' => $canManage,
        ];

        if ($canManage) {
            $data['itemCount'] = $this->items->countActiveForUser($member);
        }

        return $data;
    }

    private function memberId(Request $request): ?int
    {
        return $this->positiveInt($request->query->get('member'));
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json($data, $status, ['Cache-Control' => 'no-store, private']);
    }
}
