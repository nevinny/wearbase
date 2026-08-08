<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\PurchaseRequest;
use App\Entity\User;
use App\Form\Account\PurchaseRequestFormType;
use App\Repository\PurchaseRequestRepository;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/purchases', name: 'account_purchase_')]
class PurchaseRequestController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(PurchaseRequestRepository $requests): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->privateResponse($this->render('account/purchase/index.html.twig', [
            'requests' => $requests->findVisibleTo($user),
            'actor' => $user,
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
        $subjects = array_values(array_filter(
            $families->membersFor($user),
            static fn (User $member): bool => $member->getFamilyRole() === User::FAMILY_ROLE_CHILD
                && ($user->isFamilyParent() || $member->getId() === $user->getId()),
        ));
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
            );

            return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $purchaseRequest->getId()]));
        }

        return $this->privateResponse($this->render('account/purchase/new.html.twig', [
            'form' => $form,
            'activeSection' => 'purchases',
        ]));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(PurchaseRequest $purchaseRequest, PurchaseRequestService $service): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $service->assertCanRead($user, $purchaseRequest);

        return $this->privateResponse($this->render('account/purchase/show.html.twig', [
            'purchaseRequest' => $purchaseRequest,
            'actor' => $user,
            'activeSection' => 'purchases',
        ]));
    }

    #[Route('/{id}/decide', name: 'decide', methods: ['POST'])]
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
        $service->decide($user, $purchaseRequest, $decision, $decisionComment);

        return $this->privateResponse($this->redirectToRoute('account_purchase_show', ['id' => $purchaseRequest->getId()]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
