<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeManualOutfit;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeManualOutfitRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe/outfits/manual', name: 'account_wardrobe_manual_outfit_')]
final class WardrobeManualOutfitController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        FamilyService $family,
        WardrobeItemRepository $items,
        WardrobeManualOutfitRepository $outfits,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        $owner = $family->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        $editing = $request->query->getInt('edit');
        $outfit = $editing > 0 ? $outfits->find($editing) : null;
        if ($outfit !== null && ($outfit->getDeletedAt() !== null || $outfit->getWardrobeOwner()?->getId() !== $owner->getId())) {
            throw $this->createNotFoundException();
        }

        return $this->render('account/wardrobe/manual_outfits.html.twig', [
            'member' => $owner,
            'wardrobeItems' => $items->findActiveForUser($owner),
            'savedOutfits' => $outfits->findActiveForOwner($owner),
            'editingOutfit' => $outfit,
        ]);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(
        Request $request,
        FamilyService $family,
        WardrobeItemRepository $items,
        WardrobeManualOutfitRepository $outfits,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        $owner = $family->resolveMember($actor, $request->request->getInt('member') ?: null);
        if (!$this->isCsrfTokenValid('wardrobe_manual_outfit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }

        $id = $request->request->getInt('id');
        $outfit = $id > 0 ? $outfits->find($id) : new WardrobeManualOutfit();
        if ($outfit === null || $outfit->getDeletedAt() !== null || ($id > 0 && $outfit->getWardrobeOwner()?->getId() !== $owner->getId())) {
            throw $this->createNotFoundException();
        }

        $layout = json_decode((string) $request->request->get('layout'), true);
        if (!is_array($layout) || count($layout) < 2 || count($layout) > 12) {
            $this->addFlash('error', 'Добавьте в образ от 2 до 12 вещей');
            return $this->redirectToRoute('account_wardrobe_manual_outfit_index', ['member' => $owner->getId()]);
        }

        $allowed = [];
        foreach ($items->findActiveForUser($owner) as $item) { $allowed[$item->getId()] = $item; }
        $normalized = [];
        $selected = [];
        foreach ($layout as $index => $entry) {
            $itemId = filter_var($entry['itemId'] ?? null, FILTER_VALIDATE_INT);
            if ($itemId === false || !isset($allowed[$itemId]) || isset($selected[$itemId])) { continue; }
            $selected[$itemId] = true;
            $normalized[] = [
                'itemId' => $itemId,
                'x' => $this->bounded($entry['x'] ?? 0, 0, 100),
                'y' => $this->bounded($entry['y'] ?? 0, 0, 100),
                'width' => $this->bounded($entry['width'] ?? 35, 12, 90),
                'rotation' => $this->bounded($entry['rotation'] ?? 0, -180, 180),
                'z' => $index + 1,
            ];
        }
        if (count($normalized) < 2) {
            $this->addFlash('error', 'В образе должны быть минимум две доступные вещи');
            return $this->redirectToRoute('account_wardrobe_manual_outfit_index', ['member' => $owner->getId()]);
        }

        $outfit->clearItems();
        foreach (array_keys($selected) as $itemId) { $outfit->addItem($allowed[$itemId]); }
        $title = trim((string) $request->request->get('title'));
        $outfit->setTitle(mb_substr($title !== '' ? $title : 'Новый образ', 0, 100));
        $outfit->setLayout($normalized)->setWardrobeOwner($owner)->setCreatedBy($outfit->getCreatedBy() ?? $actor);
        $em->persist($outfit);
        $em->flush();
        $this->addFlash('success', 'Образ сохранён');

        return $this->redirectToRoute('account_wardrobe_manual_outfit_index', ['member' => $owner->getId(), 'edit' => $outfit->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, FamilyService $family, WardrobeManualOutfitRepository $outfits, EntityManagerInterface $em): Response
    {
        /** @var User $actor */ $actor = $this->getUser();
        $owner = $family->resolveMember($actor, $request->query->getInt('member') ?: null);
        $outfit = $outfits->find($id);
        if (!$outfit || $outfit->getWardrobeOwner()?->getId() !== $owner->getId()) { throw $this->createNotFoundException(); }
        if (!$this->isCsrfTokenValid('delete_manual_outfit_'.$id, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $outfit->softDelete(); $em->flush();
        return $this->redirectToRoute('account_wardrobe_manual_outfit_index', ['member' => $owner->getId()]);
    }

    private function bounded(mixed $value, float $min, float $max): float
    {
        $number = is_numeric($value) ? (float) $value : $min;
        return round(max($min, min($max, $number)), 2);
    }
}
