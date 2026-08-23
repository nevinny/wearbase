<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use App\Form\Account\PurchaseRequestFormType;
use App\Form\Account\FamilyBudgetFormType;
use App\Repository\PurchaseRequestRepository;
use App\Repository\PurchaseRequestItemRepository;
use App\Service\FamilyBudgetService;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
use App\Service\Wardrobe\PurchaseToWardrobeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/purchases', name: 'account_purchase_')]
class PurchaseRequestController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        PurchaseRequestRepository $requests,
        FamilyBudgetService $budgets,
        FamilyService $families,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $budgetSummaries = [];
        if ($user->isFamilyParent()) {
            foreach ($this->childSubjects($user, $families) as $subject) {
                $budgetSummaries[] = [
                    'subject' => $subject,
                    'summary' => $budgets->summary($subject),
                ];
            }
        }

        return $this->privateResponse($this->render('account/purchase/index.html.twig', [
            'requests' => $requests->findVisibleTo($user),
            'actor' => $user,
            'budgetSummaries' => $budgetSummaries,
            'activeSection' => 'purchases',
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        FamilyService $families,
        PurchaseRequestService $purchaseRequests,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $subjects = $this->childSubjects($user, $families);
        if ($subjects === []) {
            throw $this->createAccessDeniedException('Сначала добавьте ребёнка в семью');
        }

        $form = $this->createForm(PurchaseRequestFormType::class, null, ['subjects' => $subjects]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $purchaseRequest = $purchaseRequests->create(
                $user,
                $data['subject'],
                $data['productUrl'],
                $data['comment'],
                $data['estimatedPrice'] !== null ? (string) $data['estimatedPrice'] : null,
                array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['additionalUrls'] ?? '')) ?: []))),
            );

            return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $purchaseRequest->getId()]));
        }

        return $this->privateResponse($this->render('account/purchase/new.html.twig', [
            'form' => $form,
            'activeSection' => 'purchases',
        ]));
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        PurchaseRequest $purchaseRequest,
        PurchaseRequestService $service,
        FamilyBudgetService $budgets,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $service->assertCanRead($user, $purchaseRequest);

        $itemBudgetSummaries = [];
        foreach ($purchaseRequest->getItems() as $item) {
            $itemBudgetSummaries[$item->getId()] = $budgets->summary(
                $purchaseRequest->getSubject(),
                $item->getEstimatedPrice(),
            );
        }

        return $this->privateResponse($this->render('account/purchase/show.html.twig', [
            'purchaseRequest' => $purchaseRequest,
            'actor' => $user,
            'budgetSummary' => $budgets->summary(
                $purchaseRequest->getSubject(),
                $purchaseRequest->getEstimatedPrice(),
            ),
            'budgetPriceUnknown' => $purchaseRequest->getEstimatedPrice() === null,
            'itemBudgetSummaries' => $itemBudgetSummaries,
            'activeSection' => 'purchases',
        ]));
    }

    #[Route('/{requestId}/items/{itemId}/decide', name: 'decide_item', requirements: ['requestId' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function decideItem(
        int $requestId,
        int $itemId,
        Request $request,
        PurchaseRequestRepository $requests,
        PurchaseRequestItemRepository $items,
        PurchaseRequestService $service,
    ): Response {
        $purchaseRequest = $requests->find($requestId);
        $item = $items->find($itemId);
        if (!$purchaseRequest instanceof PurchaseRequest || !$item instanceof PurchaseRequestItem) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('purchase_item_decide_'.$itemId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }
        $decision = $request->request->getString('decision');
        if (!in_array($decision, [PurchaseRequest::STATUS_APPROVED, PurchaseRequest::STATUS_REJECTED], true)) {
            throw $this->createNotFoundException('Недопустимое решение');
        }
        $comment = $request->request->getString('decisionComment');
        if ($decision === PurchaseRequest::STATUS_REJECTED && trim($comment) === '') {
            $this->addFlash('error', 'Укажите причину отказа');
            return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $requestId]));
        }
        try {
            /** @var User $user */
            $user = $this->getUser();
            $service->decideItem($user, $purchaseRequest, $item, $decision, $comment, $request->request->getBoolean('allowOverBudget'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $requestId]));
    }

    #[Route('/{requestId}/items/{itemId}/fulfillment', name: 'fulfillment', requirements: ['requestId' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function fulfillment(
        int $requestId,
        int $itemId,
        Request $request,
        PurchaseRequestRepository $requests,
        PurchaseRequestItemRepository $items,
        PurchaseRequestService $service,
    ): Response {
        $purchaseRequest = $requests->find($requestId);
        $item = $items->find($itemId);
        if (!$purchaseRequest instanceof PurchaseRequest || !$item instanceof PurchaseRequestItem) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('purchase_fulfillment_'.$itemId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        try {
            match ($request->request->getString('action')) {
                'ordered' => $service->markOrdered($actor, $purchaseRequest, $item, $request->request->getString('actualPrice') ?: null),
                'delivered' => $service->markDelivered($actor, $purchaseRequest, $item),
                'fitting' => $service->recordFitting(
                    $actor,
                    $purchaseRequest,
                    $item,
                    $request->request->getString('outcome'),
                    $request->request->getString('triedSize') ?: null,
                    $request->request->getString('sizing') ?: null,
                    array_values(array_intersect(
                        $request->request->all('fitIssues'),
                        ['shoulders', 'chest', 'waist', 'hips', 'sleeves', 'length', 'shoe_last'],
                    )),
                    $request->request->getString('comment') ?: null,
                ),
                'returned' => $service->markReturned($actor, $purchaseRequest, $item),
                default => throw new \InvalidArgumentException('Недопустимое действие'),
            };
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $requestId]));
    }

    #[Route('/{requestId}/items/{itemId}/wardrobe', name: 'add_to_wardrobe', requirements: ['requestId' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function addToWardrobe(
        int $requestId,
        int $itemId,
        Request $request,
        PurchaseRequestRepository $requests,
        PurchaseRequestItemRepository $items,
        PurchaseToWardrobeService $service,
    ): Response {
        $purchaseRequest = $requests->find($requestId);
        $item = $items->find($itemId);
        if (!$purchaseRequest instanceof PurchaseRequest || !$item instanceof PurchaseRequestItem) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('purchase_to_wardrobe_'.$itemId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }
        /** @var User $actor */
        $actor = $this->getUser();
        try {
            $wardrobeItem = $service->add($actor, $purchaseRequest, $item);
            $this->addFlash('success', 'Вещь добавлена в гардероб');
            return $this->privateResponse($this->redirectToRoute('account_wardrobe_show', [
                'id' => $wardrobeItem->getId(),
                'member' => $purchaseRequest->getSubject()?->getId(),
            ]));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $requestId]));
        }
    }

    #[Route('/{id}/decide', name: 'decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(
        PurchaseRequest $purchaseRequest,
        Request $request,
        PurchaseRequestService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('purchase_decide_'.$purchaseRequest->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        /** @var User $user */
        $user = $this->getUser();
        $decision = $request->request->getString('decision', $request->query->getString('status'));
        if (!in_array($decision, [PurchaseRequest::STATUS_APPROVED, PurchaseRequest::STATUS_REJECTED], true)) {
            throw $this->createNotFoundException('Недопустимое решение');
        }
        $decisionComment = $request->request->getString('decisionComment');
        if ($decision === PurchaseRequest::STATUS_REJECTED && trim($decisionComment) === '') {
            $this->addFlash('error', 'Укажите причину отказа');
            return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $purchaseRequest->getId()]));
        }
        try {
            $service->decide(
                $user,
                $purchaseRequest,
                $decision,
                $decisionComment,
                $request->request->getBoolean('allowOverBudget'),
            );
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $purchaseRequest->getId()]));
    }

    #[Route('/budget/manage', name: 'budget', methods: ['GET', 'POST'])]
    public function budget(
        Request $request,
        FamilyService $families,
        FamilyBudgetService $budgets,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user->isFamilyParent()) {
            throw $this->createAccessDeniedException('Бюджет доступен только родителю');
        }

        $subjects = $this->childSubjects($user, $families);
        $form = $this->createForm(FamilyBudgetFormType::class, null, ['subjects' => $subjects]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $budgets->setMonthlyLimit($user, $data['subject'], (string) $data['monthlyLimit']);
            $this->addFlash('success', 'Месячный бюджет сохранён');

            return $this->privateResponse($this->redirectToRoute('account_purchase_index'));
        }

        $summaries = [];
        foreach ($subjects as $subject) {
            $summaries[] = ['subject' => $subject, 'summary' => $budgets->summary($subject)];
        }

        return $this->privateResponse($this->render('account/purchase/budget.html.twig', [
            'form' => $form,
            'summaries' => $summaries,
            'activeSection' => 'purchases',
        ]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    /**
     * @return User[]
     */
    private function childSubjects(User $actor, FamilyService $families): array
    {
        return array_values(array_filter(
            $families->membersFor($actor),
            static fn (User $member): bool => $member->getFamilyRole() === User::FAMILY_ROLE_CHILD
                && ($actor->isFamilyParent() || $member->getId() === $actor->getId()),
        ));
    }
}
