<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Form\Account\WardrobeItemFormType;
use App\Repository\WardrobeItemRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe', name: 'account_wardrobe_')]
class WardrobeController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(WardrobeItemRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $items = $repo->findActiveForUser($user);
        $stats = $repo->getStats($user);

        return $this->render('account/wardrobe/index.html.twig', [
            'items'      => $items,
            'stats'      => $stats,
            'totalCount' => (int) array_sum(array_column($stats, 'cnt')),
            'totalSum'   => array_sum(array_map('floatval', array_column($stats, 'total'))),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        WardrobeItemRepository $repo,
        ManagerRegistry $doctrine,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $item = new WardrobeItem();
        $form = $this->createForm(WardrobeItemFormType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $item->setUser($user);
            $item->setItemNo($repo->nextItemNo($user));
            $item->setSource(WardrobeItem::SOURCE_WEB);

            $em = $doctrine->getManager();
            try {
                $em->persist($item);
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Гонка за item_no: один retry со свежим номером (EM после исключения закрыт)
                $doctrine->resetManager();
                $em = $doctrine->getManager();
                /** @var User $user */
                $user = $em->find(User::class, $user->getId());
                $item->setUser($user);
                $item->setItemNo($repo->nextItemNo($user));
                $em->persist($item);
                $em->flush();
            }

            $this->addFlash('success', 'Вещь добавлена');
            return $this->redirectToRoute('account_wardrobe_index');
        }

        return $this->render('account/wardrobe/form.html.twig', [
            'form'       => $form,
            'item'       => $item,
            'categories' => array_unique(array_merge(
                $repo->distinctCategories($user),
                WardrobeItem::SUGGESTED_CATEGORIES,
            )),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, WardrobeItemRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $item = $repo->findActiveOneForUser($id, $user);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        return $this->render('account/wardrobe/show.html.twig', [
            'item' => $item,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Чужая или удалённая вещь → 404 (ownership гарантирует финдер)
        $item = $repo->findActiveOneForUser($id, $user);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(WardrobeItemFormType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Изменения сохранены');
            return $this->redirectToRoute('account_wardrobe_show', ['id' => $item->getId()]);
        }

        return $this->render('account/wardrobe/form.html.twig', [
            'form'       => $form,
            'item'       => $item,
            'categories' => array_unique(array_merge(
                $repo->distinctCategories($user),
                WardrobeItem::SUGGESTED_CATEGORIES,
            )),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_wardrobe_item', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_wardrobe_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $item = $repo->findActiveOneForUser($id, $user);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        // Только soft-delete — физический DELETE по действию пользователя запрещён
        $item->softDelete();
        $em->flush();
        $this->addFlash('success', 'Вещь удалена');

        return $this->redirectToRoute('account_wardrobe_index');
    }
}
