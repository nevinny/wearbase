<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeWearEvent;
use App\Service\FamilyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Storage\StorageInterface;

#[Route('/account/wardrobe/media', name: 'account_wardrobe_media_')]
final class WardrobeMediaController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    #[Route('/item/{id}', name: 'item', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function item(WardrobeItem $item, FamilyService $families, StorageInterface $storage): Response
    {
        $this->assertCanView($item->getUser(), $families);

        return $this->mediaResponse($storage->resolvePath($item, 'photoFile'), $item->getPhoto(), 'wardrobe');
    }

    #[Route('/photo/{id}', name: 'photo', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function photo(WardrobeItemPhoto $photo, FamilyService $families, StorageInterface $storage): Response
    {
        if ($photo->isDeleted()) {
            throw $this->createNotFoundException();
        }
        $this->assertCanView($photo->getItem()?->getUser(), $families);

        return $this->mediaResponse($storage->resolvePath($photo, 'file'), $photo->getFilePath(), 'wardrobe');
    }

    #[Route('/draft/{id}', name: 'draft', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function draft(WardrobeItemDraft $draft, FamilyService $families, StorageInterface $storage): Response
    {
        $this->assertCanView($draft->getProfileSubject(), $families);

        return $this->mediaResponse($storage->resolvePath($draft, 'photoFile'), $draft->getPhoto(), 'wardrobe_drafts');
    }

    #[Route('/wear/{id}', name: 'wear', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function wear(WardrobeWearEvent $event, FamilyService $families, StorageInterface $storage): Response
    {
        $this->assertCanView($event->getProfileSubject(), $families);

        return $this->mediaResponse($storage->resolvePath($event, 'photoFile'), null, 'wardrobe_wear');
    }

    private function assertCanView(?User $subject, FamilyService $families): void
    {
        $actor = $this->getUser();
        if (!$actor instanceof User || !$subject instanceof User || !$families->canManage($actor, $subject)) {
            throw $this->createNotFoundException();
        }
    }

    private function mediaResponse(?string $path, ?string $legacyName, string $legacyDirectory): BinaryFileResponse
    {
        if (($path === null || !is_file($path)) && $legacyName !== null) {
            $root = realpath($this->projectDir.'/public_html/images/'.$legacyDirectory);
            $legacyPath = realpath($this->projectDir.'/public_html/images/'.$legacyDirectory.'/'.$legacyName);
            if ($root !== false && $legacyPath !== false && str_starts_with($legacyPath, $root.DIRECTORY_SEPARATOR)) {
                $path = $legacyPath;
            }
        }
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
