<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\WardrobeItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Storage\StorageInterface;

#[Route('/api/v1/wardrobe/outfit-images')]
final class WardrobeOutfitImageController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(default::AGENT_API_TOKEN)%')] private readonly ?string $token,
        #[Autowire('%env(default::AGENT_API_SECRET)%')] private readonly ?string $secret,
        #[Autowire('%env(SITE_BASE_URL)%')] private readonly string $siteUrl,
        #[Autowire('%kernel.project_dir%/public_html/images/wardrobe-outfits')] private readonly string $outputDir,
    ) {}

    #[Route('', name: 'api_wardrobe_outfit_images_pending', methods: ['GET'])]
    public function pending(Request $request, WardrobeItemRepository $items, StorageInterface $storage): JsonResponse
    {
        if (!$this->authorized($request)) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $after = max(0, $request->query->getInt('after'));
        $candidates = $items->findImageCandidates($after, 100);
        $pending = [];
        foreach ($candidates as $item) {
            $cover = $item->getCoverPhoto();
            $source = $cover ? $storage->resolvePath($cover, 'file') : $storage->resolvePath($item, 'photoFile');
            $uri = $cover ? $storage->resolveUri($cover, 'file') : $storage->resolveUri($item, 'photoFile');
            if ($source === null || $uri === null || !is_file($source)) {
                continue;
            }
            $hash = hash_file('sha256', $source);
            if ($hash === $item->getOutfitImageSourceHash() && $item->getOutfitImagePath() !== null) {
                continue;
            }
            $pending[] = [
                'id' => $item->getId(),
                'source_hash' => $hash,
                'photo_url' => rtrim($this->siteUrl, '/') . $uri,
            ];
        }

        return $this->json([
            'items' => $pending,
            'next_after' => $candidates === [] ? null : end($candidates)->getId(),
        ]);
    }

    #[Route('/{id}', name: 'api_wardrobe_outfit_images_store', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function store(int $id, Request $request, WardrobeItemRepository $items, StorageInterface $storage, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->authorized($request, true)) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (strlen($request->getContent()) > 14_000_000) {
            return $this->json(['error' => 'image too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $item = $items->find($id);
        if ($item === null || $item->getDeletedAt() !== null) {
            return $this->json(['error' => 'item not found'], Response::HTTP_NOT_FOUND);
        }
        $data = json_decode($request->getContent(), true);
        $cover = $item->getCoverPhoto();
        $source = $cover ? $storage->resolvePath($cover, 'file') : $storage->resolvePath($item, 'photoFile');
        if (!is_array($data) || $source === null || !is_file($source)
            || !hash_equals(hash_file('sha256', $source), (string) ($data['source_hash'] ?? ''))) {
            return $this->json(['error' => 'source changed'], Response::HTTP_CONFLICT);
        }
        $bytes = base64_decode((string) ($data['image_base64'] ?? ''), true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || strlen($bytes) > 10_000_000 || $size === false
            || $size['mime'] !== 'image/png' || $size[0] > 5000 || $size[1] > 5000) {
            return $this->json(['error' => 'invalid PNG'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0775, true) && !is_dir($this->outputDir)) {
            return $this->json(['error' => 'storage unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $name = hash('sha256', $bytes) . '.png';
        $target = $this->outputDir . '/' . $name;
        if (!is_file($target)) {
            $temporary = tempnam($this->outputDir, 'outfit-');
            if ($temporary === false || file_put_contents($temporary, $bytes) === false || !rename($temporary, $target)) {
                return $this->json(['error' => 'storage unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }
        $path = '/images/wardrobe-outfits/' . $name;
        $item->setOutfitImage($path, $data['source_hash']);
        $em->flush();

        return $this->json(['image_url' => $path]);
    }

    private function authorized(Request $request, bool $signed = false): bool
    {
        if (!$this->token || !hash_equals($this->token, (string) $request->headers->get('X-Agent-Token'))) {
            return false;
        }
        return !$signed || ($this->secret !== null && $this->secret !== '' && hash_equals(
            hash_hmac('sha256', $request->getContent(), $this->secret),
            (string) $request->headers->get('X-Signature'),
        ));
    }
}
