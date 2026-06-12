<?php

declare(strict_types=1);

namespace App\Controller\Pages;

use App\Repository\ShippingRuleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PageController extends AbstractController
{
    private const CARRIER_ICONS = [
        'courier'  => '🏍️',
        'cdek'     => '📦',
        'boxberry' => '📦',
        'pochta'   => '✉️',
        'dhl'      => '✈️',
        'fedex'    => '✈️',
        'pickup'   => '📍',
    ];

    #[Route('/{_locale}/delivery', name: 'delivery_info', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function delivery(ShippingRuleRepository $ruleRepo): Response
    {
        $grouped = $ruleRepo->findAllGroupedByCountry();

        return $this->render('pages/delivery.html.twig', [
            'grouped'      => $grouped,
            'carrierIcons' => self::CARRIER_ICONS,
        ]);
    }

    #[Route('/{_locale}/privacy', name: 'privacy_policy', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function privacy(): Response
    {
        return $this->render('pages/privacy.html.twig');
    }

    #[Route('/{_locale}/terms', name: 'terms_of_use', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function terms(): Response
    {
        return $this->render('pages/terms.html.twig');
    }

    #[Route('/{_locale}/cookies', name: 'cookies_policy', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function cookies(): Response
    {
        return $this->render('pages/cookies.html.twig');
    }

    #[Route('/{_locale}/personal-data-consent', name: 'personal_data_consent', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function personalDataConsent(): Response
    {
        return $this->render('pages/consent.html.twig');
    }
}
