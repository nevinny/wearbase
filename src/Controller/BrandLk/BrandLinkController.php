<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\Brand;
use App\Entity\BrandDatapoint;
use App\Entity\BrandLink;
use App\Form\BrandLk\BrandLinkFormType;
use App\Repository\BrandDatapointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ЛК бренда: управление ссылками (сайт/соцсети, BrandLink). До этого ссылки жили
 * только из enrichment'а — теперь владелец вносит/правит сам.
 *
 * Owner-provenance — тот же приём, что в BrandStoreController::markOwnerProvenance():
 * owner-правка помечает datapoint'ы ссылки provenance=owner + ownerEditedAt, так что
 * ре-обогащение их не затирает.
 */
#[Route('/brand/links', name: 'brand_links')]
class BrandLinkController extends BrandDashboardController
{
    private const MAX_LINKS_PER_BRAND = 8;

    #[Route('', name: '')]
    public function index(Request $request): Response
    {
        $brand = $this->getActiveBrand();

        $editLink = null;
        if ($request->query->has('edit')) {
            $editLink = $this->findOwnLink((int) $request->query->get('edit'));
        }

        $form = $this->createForm(BrandLinkFormType::class, $editLink ?? new BrandLink());

        return $this->render('brand_lk/links.html.twig', [
            'brand'    => $brand,
            'links'    => $brand->getActiveLinks(),
            'form'     => $form,
            'editLink' => $editLink,
        ]);
    }

    #[Route('/save', name: '_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): Response
    {
        $brand = $this->getActiveBrand();

        $id   = $request->request->getInt('id');
        $link = null;
        if ($id > 0) {
            $link = $this->findOwnLink($id);
            if ($link === null) {
                // Чужая ссылка или мусорный id — 404 (IDOR), не палим существование чужого бренда.
                throw $this->createNotFoundException('Ссылка не найдена.');
            }
        }

        $isNew = $link === null;
        if ($isNew && $brand->getActiveLinks()->count() >= self::MAX_LINKS_PER_BRAND) {
            $this->addFlash('danger', sprintf('Можно добавить не больше %d ссылок на бренд.', self::MAX_LINKS_PER_BRAND));
            return $this->redirectToRoute('brand_links');
        }

        $link ??= new BrandLink();
        $form = $this->createForm(BrandLinkFormType::class, $link);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isDuplicateUrl($brand, $link)) {
                $this->addFlash('danger', 'Такая ссылка уже добавлена.');
                return $this->redirectToRoute('brand_links');
            }

            if ($isNew) {
                $link->setBrand($brand);
                // DefaultFields требует slug NOT NULL — тот же приём, что в enrichment
                // (BrandIngestService/EnrichBrandContactsCommand): генерируем из типа+URL.
                $link->setSlug(substr(md5($link->getLinkType() . $link->getLinkUrl()), 0, 24));
                $em->persist($link);
            }
            $em->flush(); // link.id нужен для datapoint'ов новой ссылки

            $this->markOwnerProvenance($link, $em);
            $em->flush();

            $this->addFlash('success', $isNew ? 'Ссылка добавлена.' : 'Ссылка обновлена.');

            return $this->redirectToRoute('brand_links');
        }

        return $this->render('brand_lk/links.html.twig', [
            'brand'    => $brand,
            'links'    => $brand->getActiveLinks(),
            'form'     => $form,
            'editLink' => $isNew ? null : $link,
        ]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        // Ownership-проверка ПЕРЕД CSRF (как в WardrobeController::resolvePhotoAction) —
        // чужая/несуществующая ссылка всегда 404, независимо от токена (IDOR не палит существование).
        $link = $this->findOwnLink($id);
        if ($link === null) {
            throw $this->createNotFoundException('Ссылка не найдена.');
        }

        if (!$this->isCsrfTokenValid('brand_links', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Недействительный токен. Обновите страницу.');
            return $this->redirectToRoute('brand_links');
        }

        // Правило проекта: НИКАКОГО физического DELETE от пользователя — только soft.
        $link->setStatus(Statuses::Deleted);
        $em->flush();

        $this->addFlash('success', 'Ссылка удалена.');

        return $this->redirectToRoute('brand_links');
    }

    /** Ссылка активного бренда пользователя (не чужая и не удалённая). */
    private function findOwnLink(int $id): ?BrandLink
    {
        $brand = $this->getActiveBrand();
        foreach ($brand->getActiveLinks() as $link) {
            if ($link->getId() === $id) {
                return $link;
            }
        }

        return null;
    }

    /** Дубль по нормализованному URL среди активных ссылок бренда (кроме самой редактируемой). */
    private function isDuplicateUrl(Brand $brand, BrandLink $link): bool
    {
        $normalized = mb_strtolower(rtrim((string) $link->getLinkUrl(), '/'));
        foreach ($brand->getActiveLinks() as $existing) {
            if ($existing->getId() === $link->getId()) {
                continue;
            }
            if (mb_strtolower(rtrim((string) $existing->getLinkUrl(), '/')) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /** Owner-правка: provenance=owner + ownerEditedAt на все поля ссылки, счётчики голосов сбрасываются. */
    private function markOwnerProvenance(BrandLink $link, EntityManagerInterface $em): void
    {
        /** @var BrandDatapointRepository $repo */
        $repo = $em->getRepository(BrandDatapoint::class);
        foreach (BrandDatapoint::FIELDS[BrandDatapoint::TYPE_LINK] as $field) {
            $repo->getOrCreate($link->getBrand(), BrandDatapoint::TYPE_LINK, $link->getId(), $field)
                ->setProvenance(BrandDatapoint::PROV_OWNER)
                ->setOwnerEditedAt(new \DateTime())
                ->setState(BrandDatapoint::STATE_ACTIVE)
                ->setConfirmCount(0)->setRejectCount(0)->setRejectWindow(0)
                ->setQueuedRevalidateAt(null);
        }
    }
}
