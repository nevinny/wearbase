<?php

namespace App\Controller\Brands;

use App\Entity\Brand;
use App\Entity\User;
use App\Service\DatapointVoteService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Краудсорс-валидация: приём голосов ✓/✗ (+ «предложить исправление») за data-point'ы
 * бренда от АНОНИМНЫХ посетителей. Анти-абьюз: rate-limit 20/час/IP + дедуп по
 * voter_hash в сервисе. CSRF не применяется (stateless POST, как вебхуки) —
 * защита лимитером и отпечатком.
 */
class BrandDatapointController extends AbstractController
{
    #[Route('/brand-data/{slug}/vote', name: 'brand_datapoint_vote', methods: ['POST'])]
    public function vote(
        #[MapEntity(mapping: ['slug' => 'slug'])] Brand $brand,
        Request $request,
        DatapointVoteService $votes,
        RateLimiterFactory $brandVoteLimiter,
    ): JsonResponse {
        if (!$brandVoteLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'rate limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();

        try {
            $result = $votes->applyVote(
                brand:      $brand,
                targetType: (string) ($data['target_type'] ?? ''),
                targetId:   isset($data['target_id']) ? (int) $data['target_id'] : null,
                field:      (string) ($data['field'] ?? ''),
                vote:       (string) ($data['vote'] ?? ''),
                suggestion: isset($data['suggestion']) ? (string) $data['suggestion'] : null,
                clientIp:   $request->getClientIp() ?? 'unknown',
                userAgent:  (string) $request->headers->get('User-Agent', ''),
                userId:     $user instanceof User ? $user->getId() : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result);
    }
}
