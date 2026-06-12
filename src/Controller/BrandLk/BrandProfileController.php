<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Form\BrandLk\BrandProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand', name: 'brand_')]
class BrandProfileController extends BrandDashboardController
{
    #[Route('/profile', name: 'profile')]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $brand = $this->getActiveBrand();
        $form  = $this->createForm(BrandProfileFormType::class, $brand);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Профиль обновлён');
            return $this->redirectToRoute('brand_profile');
        }

        return $this->render('brand_lk/profile.html.twig', [
            'brand' => $brand,
            'form'  => $form,
        ]);
    }
}
