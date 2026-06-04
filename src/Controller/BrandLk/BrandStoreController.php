<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\BrandDatapoint;
use App\Entity\BrandStore;
use App\Repository\BrandDatapointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ЛК бренда: управление офлайн-магазинами (BrandStore). До этого точки жили
 * только из enrichment'а («спящие» данные) — теперь владелец вносит/правит сам.
 *
 * Связка с краудсорс-валидацией: owner-правка помечает datapoint'ы точки
 * provenance=owner + ownerEditedAt — такие данные ре-обогащение НЕ затирает,
 * а голоса посетителей не скрывают (режим claimed; см. tasktracker).
 */
#[Route('/brand/stores', name: 'brand_stores')]
class BrandStoreController extends BrandDashboardController
{
    #[Route('', name: '')]
    public function index(Request $request): Response
    {
        $brand = $this->getActiveBrand();

        $editStore = null;
        if ($request->query->has('edit')) {
            $editStore = $this->findOwnStore((int) $request->query->get('edit'));
        }

        return $this->render('brand_lk/stores.html.twig', [
            'brand'     => $brand,
            'stores'    => $brand->getActiveStores(),
            'editStore' => $editStore,
        ]);
    }

    #[Route('/save', name: '_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('brand_stores', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Недействительный токен. Обновите страницу.');
            return $this->redirectToRoute('brand_stores');
        }

        $brand   = $this->getActiveBrand();
        $address = trim((string) $request->request->get('address'));
        if ($address === '') {
            $this->addFlash('danger', 'Адрес обязателен.');
            return $this->redirectToRoute('brand_stores');
        }

        $id = (int) $request->request->get('id');
        $store = $id > 0 ? $this->findOwnStore($id) : null;
        if ($id > 0 && $store === null) {
            $this->addFlash('danger', 'Магазин не найден.');
            return $this->redirectToRoute('brand_stores');
        }

        if ($store === null) {
            $store = (new BrandStore())->setBrand($brand);
            $em->persist($store);
        }

        $store->setAddress(mb_substr($address, 0, 500))
            ->setCity(self::nullable($request->request->get('city')))
            ->setPhone(self::nullable($request->request->get('phone')))
            ->setWorkHours(self::nullable($request->request->get('work_hours')))
            ->setSource('owner');
        $em->flush(); // store.id нужен для datapoint'ов новой точки

        $this->markOwnerProvenance($store, $em);
        $em->flush();

        $this->addFlash('success', $id > 0 ? 'Магазин обновлён.' : 'Магазин добавлен.');

        return $this->redirectToRoute('brand_stores');
    }

    #[Route('/{id}/delete', name: '_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('brand_stores', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Недействительный токен. Обновите страницу.');
            return $this->redirectToRoute('brand_stores');
        }

        $store = $this->findOwnStore($id);
        if ($store === null) {
            $this->addFlash('danger', 'Магазин не найден.');
            return $this->redirectToRoute('brand_stores');
        }

        // Правило проекта: НИКАКОГО физического DELETE от пользователя — только soft.
        // Datapoint'ы/голоса точки сохраняются (история; со страницы точка уходит вместе с магазином).
        $store->setStatus(\Nevinny\AdminCoreBundle\Enum\Statuses::Deleted);
        $em->flush();

        $this->addFlash('success', 'Магазин удалён.');

        return $this->redirectToRoute('brand_stores');
    }

    /** Магазин активного бренда пользователя (не чужой и не удалённый). */
    private function findOwnStore(int $id): ?BrandStore
    {
        $brand = $this->getActiveBrand();
        foreach ($brand->getActiveStores() as $store) {
            if ($store->getId() === $id) {
                return $store;
            }
        }

        return null;
    }

    /** Owner-правка: provenance=owner + ownerEditedAt на все поля точки, счётчики голосов сбрасываются. */
    private function markOwnerProvenance(BrandStore $store, EntityManagerInterface $em): void
    {
        /** @var BrandDatapointRepository $repo */
        $repo = $em->getRepository(BrandDatapoint::class);
        foreach (BrandDatapoint::FIELDS[BrandDatapoint::TYPE_STORE] as $field) {
            $repo->getOrCreate($store->getBrand(), BrandDatapoint::TYPE_STORE, $store->getId(), $field)
                ->setProvenance(BrandDatapoint::PROV_OWNER)
                ->setOwnerEditedAt(new \DateTime())
                ->setState(BrandDatapoint::STATE_ACTIVE)
                ->setConfirmCount(0)->setRejectCount(0)->setRejectWindow(0)
                ->setQueuedRevalidateAt(null);
        }
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
