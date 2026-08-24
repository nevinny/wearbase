<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeOutfitShare;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeOutfitShareRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Создание/подтверждение/отзыв гостевых ссылок на лук из ЛК («Поделиться луком», §1.3).
 *
 * Приватность несовершеннолетних (§4, решение PO №3): подросток инициирует сам,
 * но ссылка создаётся со статусом pending_parent и не выдаёт токен до аппрува родителя;
 * родитель создаёт ссылку сразу активной (двойной opt-in не нужен — он и есть согласие).
 */
#[Route('/account/wardrobe/outfits')]
class WardrobeOutfitShareController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WardrobeOutfitRepository $outfits,
        private readonly WardrobeOutfitShareRepository $shares,
        private readonly FamilyService $families,
    ) {}

    #[Route('/{outfitId}/share', name: 'account_wardrobe_outfit_share_create', requirements: ['outfitId' => '\\d+'], methods: ['POST'])]
    public function create(int $outfitId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wardrobe_outfit_share_' . $outfitId, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $outfit = $this->outfits->find($outfitId);
        if ($outfit === null || !$this->canShare($actor, $outfit)) {
            throw $this->createNotFoundException();
        }

        // Одна строка = одна ссылка (канал-на-ссылку): повторный «Поделиться» создаёт новую ссылку.
        $share = new WardrobeOutfitShare($outfit, $actor, $this->resolveTtl($request));
        if ($actor->getId() !== $outfit->getWardrobeOwner()?->getId()
            || $actor->getFamilyRole() !== User::FAMILY_ROLE_CHILD) {
            // Владелец-взрослый или родитель ребёнка: ссылка активна сразу.
            $share->approve();
        }
        $this->em->persist($share);
        $this->em->flush();

        $this->addFlash('success', $share->isPendingParent()
            ? 'Ссылка создана и ждёт подтверждения родителя'
            : 'Ссылка на образ готова');

        return $this->redirectToRoute('account_wardrobe_outfits', $this->memberQuery($actor, $outfit));
    }

    #[Route('/share/{id}/confirm', name: 'account_wardrobe_outfit_share_confirm', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function confirm(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wardrobe_outfit_share_confirm_' . $id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        $share = $this->shares->find($id);
        if ($share === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $actor */
        $actor = $this->getUser();
        if (!$this->families->canManage($actor, $share->getOutfit()->getWardrobeOwner())) {
            throw $this->createNotFoundException();
        }

        if ($request->request->get('action') === 'approve') {
            $share->approve();
            $this->em->flush();
            $this->addFlash('success', 'Ссылка подтверждена');
        } else {
            $share->revoke();
            $this->em->flush();
            $this->addFlash('success', 'Ссылка отклонена');
        }

        return $this->redirectToRoute('account_wardrobe_outfits', $this->memberQuery($actor, $share->getOutfit()));
    }

    #[Route('/share/{id}/revoke', name: 'account_wardrobe_outfit_share_revoke', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function revoke(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wardrobe_outfit_share_revoke_' . $id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        $share = $this->shares->find($id);
        if ($share === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $owner = $share->getOutfit()->getWardrobeOwner();
        if ($share->getCreatedBy()->getId() !== $actor->getId() && !$this->families->canManage($actor, $owner)) {
            throw $this->createNotFoundException();
        }

        $share->revoke();
        $this->em->flush();
        // Честная формулировка UX (решение PO №5): страница скрыта, но превью в переписке остаётся.
        $this->addFlash('success', 'Ссылка отозвана: страница больше недоступна (превью в переписке не удаляется)');

        return $this->redirectToRoute('account_wardrobe_outfits', $this->memberQuery($actor, $share->getOutfit()));
    }

    private function canShare(User $actor, WardrobeOutfit $outfit): bool
    {
        return $outfit->getUser()?->getId() === $actor->getId()
            || $this->families->canManage($actor, $outfit->getWardrobeOwner());
    }

    private function resolveTtl(Request $request): string
    {
        $ttl = (string) $request->request->get('ttl', WardrobeOutfitShare::DEFAULT_TTL);

        return isset(WardrobeOutfitShare::TTL_OPTIONS[$ttl]) ? $ttl : WardrobeOutfitShare::DEFAULT_TTL;
    }

    /** @return array{member?: int} */
    private function memberQuery(User $actor, WardrobeOutfit $outfit): array
    {
        $ownerId = $outfit->getWardrobeOwner()?->getId();

        return $ownerId !== null && $ownerId !== $actor->getId() ? ['member' => $ownerId] : [];
    }
}
