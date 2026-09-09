<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItemLifecycleEvent;
use App\Repository\WardrobeItemLifecycleEventRepository;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeItemLifecycleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe', name: 'account_wardrobe_lifecycle_')]
final class WardrobeLifecycleController extends AbstractController
{
    #[Route('/{id}/lifecycle', name: 'create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(int $id, Request $request, WardrobeItemRepository $items, FamilyService $families, WardrobeItemLifecycleService $service): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $families->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        $item = $items->findActiveOneForUser($id, $subject);
        if ($item === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wardrobe_lifecycle_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }
        try {
            if ($request->request->getString('type') === WardrobeItemLifecycleEvent::TYPE_TRANSFER_EXTERNAL) {
                $service->transferOutside($actor, $subject, $item, $request->request->getString('provider') ?: null, $request->request->getString('note') ?: null);
                $this->addFlash('success', 'Вещь передана вне семьи');
                return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($actor, $subject));
            }
            $service->sendToCare($actor, $subject, $item, $request->request->getString('type'), $request->request->getString('provider') ?: null, $request->request->getString('cost') ?: null, $request->request->getString('note') ?: null);
            $this->addFlash('success', 'Событие ухода сохранено');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('account_wardrobe_show', ['id' => $id] + $this->memberQuery($actor, $subject));
    }

    #[Route('/{id}/lifecycle/{eventId}/complete', name: 'complete', requirements: ['id' => '\d+', 'eventId' => '\d+'], methods: ['POST'])]
    public function complete(int $id, int $eventId, Request $request, WardrobeItemRepository $items, WardrobeItemLifecycleEventRepository $events, FamilyService $families, WardrobeItemLifecycleService $service): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $families->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        $item = $items->findActiveOneForUser($id, $subject);
        $event = $events->find($eventId);
        if ($item === null || $event === null || $event->getItem()->getId() !== $item->getId()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wardrobe_lifecycle_complete_'.$eventId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }
        $service->completeCare($actor, $subject, $event);
        $this->addFlash('success', 'Вещь вернулась в гардероб');
        return $this->redirectToRoute('account_wardrobe_show', ['id' => $id] + $this->memberQuery($actor, $subject));
    }

    /** @return array<string, int> */
    private function memberQuery(User $actor, User $subject): array
    {
        return $actor->getId() === $subject->getId() ? [] : ['member' => (int) $subject->getId()];
    }
}
