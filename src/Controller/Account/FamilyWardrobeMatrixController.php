<?php

declare(strict_types=1);

namespace App\Controller\Account;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeNeed;
use App\Form\Account\WardrobeClassificationFormType;
use App\Form\Account\WardrobeNeedFormType;
use App\Repository\WardrobeCategoryRepository;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use App\Service\Family\FamilyWardrobeMatrix;
use App\Service\Family\WardrobeNeedService;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/account/family/matrix', name: 'account_family_')]
final class FamilyWardrobeMatrixController extends AbstractController
{
    public function __construct(private readonly WardrobeCategoryRepository $categories) {}

    #[Route('', name: 'matrix', methods: ['GET'])]
    public function index(Request $request, FamilyWardrobeMatrix $matrix): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $season = $this->season($request->query->getString('season'));
        return $this->renderMatrix($actor, $season, $matrix);
    }

    #[Route('/items/{id}/classify', name: 'item_classify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function classify(
        int $id,
        Request $request,
        WardrobeItemRepository $items,
        FamilyService $families,
        FamilyWardrobeMatrix $matrix,
        WardrobeManager $wardrobes,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        $item = $items->findActiveOne($id);
        if ($item === null) {
            throw $this->createNotFoundException('Вещь не найдена');
        }
        if (!$families->canManage($actor, $item->getUser())) {
            throw $this->createAccessDeniedException('Нет доступа к этому гардеробу');
        }
        if (in_array($item->getItemStatus(), [...WardrobeItem::ARCHIVE_STATUSES, WardrobeItem::ITEM_TRANSFERRED], true)
            || $item->getWearStatus() === WardrobeItem::WEAR_GIVEN_AWAY
        ) {
            throw $this->createNotFoundException('Вещь недоступна в матрице');
        }
        $season = $this->season($request->query->getString('season'));
        $form = $this->classificationForm($item, $this->categories->findActiveTree(), $season);
        $token = $request->request->all($form->getName())['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid($form->getName(), $token)) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $item->setCategoryRef($data['category'])->setSeason($data['season']);
            $wardrobes->refreshCompletionStatus($item);
            $em->flush();
            $this->addFlash('success', 'Тип вещи и сезон сохранены');
            return $this->matrixRedirect($season);
        }
        return $this->renderMatrix($actor, $season, $matrix, $form, $item);
    }

    #[Route('/needs/new', name: 'need_new', methods: ['GET', 'POST'])]
    #[Route('/needs/{id}/edit', name: 'need_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function form(Request $request, WardrobeNeedService $needs, WardrobeCategoryRepository $categories, ?int $id = null): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $children = $needs->subjectsFor($actor);
        $need = $id === null ? null : $needs->findForActor($actor, $id);
        if ($need !== null) {
            $children = [$need->getSubject()];
            $data = [
                'subject' => $need->getSubject(), 'category' => $need->getCategory(),
                'season' => $need->getSeason(), 'title' => $need->getTitle(),
                'quantity' => $need->getQuantity(), 'size' => $need->getSize(), 'notes' => $need->getNotes(),
            ];
        } else {
            $subject = $children[0];
            $memberId = $request->query->getInt('member');
            if ($memberId > 0) {
                $matches = array_filter($children, static fn (User $child): bool => $child->getId() === $memberId);
                $subject = reset($matches) ?: throw $this->createAccessDeniedException('Нет доступа к этому гардеробу');
            }
            $category = $categories->find($request->query->getInt('category'));
            $data = [
                'subject' => $subject, 'category' => $category?->isActive() ? $category : null,
                'season' => $this->season($request->query->getString('season')) ?: 'all',
                'title' => $category?->getName(), 'quantity' => 1, 'size' => null, 'notes' => null,
            ];
        }
        $returnSeason = $this->season($request->query->getString('returnSeason', $request->query->getString('season', $need?->getSeason() ?? '')));
        $form = $this->createForm(WardrobeNeedFormType::class, $data, ['subjects' => $children]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $needs->save($actor, $form->getData(), $need);
                $this->addFlash('success', 'Потребность сохранена');
                return $this->matrixRedirect($returnSeason);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }
        return $this->privateResponse($this->render('account/family_wardrobe/need_form.html.twig', [
            'form' => $form, 'need' => $need, 'returnSeason' => $returnSeason, 'familyActiveSection' => 'family',
        ], new Response(status: $form->isSubmitted() ? 422 : 200)));
    }

    #[Route('/needs/{id}/status', name: 'need_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(int $id, Request $request, WardrobeNeedService $needs): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $need = $needs->findForActor($actor, $id);
        if (!$this->isCsrfTokenValid('wardrobe_need_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }
        $action = $request->request->getString('action');
        if (!in_array($action, ['close', 'reopen'], true)) {
            throw new BadRequestHttpException('Недопустимое действие');
        }
        $season = $this->season($request->query->getString('season'));
        $needs->setOpen($actor, $need, $action === 'reopen');
        $this->addFlash('success', $action === 'close' ? 'Потребность закрыта' : 'Потребность снова открыта');
        return $this->matrixRedirect($season);
    }

    private function season(string $season): string
    {
        if ($season !== '' && !isset(WardrobeNeed::SEASONS[$season])) {
            throw new BadRequestHttpException('Неизвестный сезон');
        }
        return $season;
    }

    private function classificationForm(WardrobeItem $item, array $categories, string $season): FormInterface
    {
        $name = 'wardrobe_classification_'.$item->getId();
        $category = $item->getCategoryRef();
        if ($category === null || !$category->isActive()) {
            $category = $this->categories->resolveActive((string) $item->getCategory(), $categories);
        }
        return $this->container->get('form.factory')->createNamed($name, WardrobeClassificationFormType::class, [
            'category' => $category,
            'season' => isset(WardrobeNeed::SEASONS[$item->getSeason() ?? '']) ? $item->getSeason() : null,
        ], [
            'categories' => $categories,
            'csrf_token_id' => $name,
            'action' => $this->generateUrl('account_family_item_classify', ['id' => $item->getId(), 'season' => $season]),
        ]);
    }

    private function renderMatrix(User $actor, string $season, FamilyWardrobeMatrix $matrix, ?FormInterface $invalidForm = null, ?WardrobeItem $invalidItem = null): Response
    {
        $overview = $matrix->overview($actor, $season);
        if ($invalidItem !== null && !in_array($invalidItem, $overview['uncategorized'], true)) {
            $overview['uncategorized'][] = $invalidItem;
        }
        $classificationForms = [];
        foreach ($overview['uncategorized'] as $item) {
            $form = $invalidItem?->getId() === $item->getId() ? $invalidForm : $this->classificationForm($item, $overview['categories'], $season);
            $classificationForms[$item->getId()] = $form->createView();
        }
        return $this->privateResponse($this->render('account/family_wardrobe/matrix.html.twig', [
            'matrix' => $overview,
            'classificationForms' => $classificationForms,
            'selectedSeason' => $season,
            'seasons' => WardrobeNeed::SEASONS,
            'groups' => FamilyWardrobeMatrix::GROUPS,
            'familyActiveSection' => 'family',
        ], new Response(status: $invalidForm === null ? 200 : 422)));
    }

    private function matrixRedirect(string $season): Response
    {
        return $this->privateResponse($this->redirectToRoute('account_family_matrix', $season === '' ? [] : ['season' => $season]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
