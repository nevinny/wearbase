<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Brand;
use App\Entity\BrandAudience;
use App\Entity\BrandImage;
use App\Entity\BrandLink;
use App\Entity\BrandSize;
use App\Entity\BrandStyle;
use App\Entity\BrandTier;
use App\Entity\City;
use App\Entity\Country;
use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Entity\Language;
use App\Entity\ScheduledCommand;
use App\Entity\BrandMarket;
use App\Entity\ShippingRule;
use App\Entity\TaxRule;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Nevinny\AdminCoreBundle\Entity\SectionLink;
use Nevinny\AdminCoreBundle\Entity\SectionType;
use Nevinny\AdminCoreBundle\Entity\User;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
//        return parent::index();

        // Option 1. You can make your dashboard redirect to some common page of your backend
        //
        // 1.1) If you have enabled the "pretty URLs" feature:
        // return $this->redirectToRoute('admin_user_index');
        //
        // 1.2) Same example but using the "ugly URLs" that were used in previous EasyAdmin versions:
        // $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        // return $this->redirect($adminUrlGenerator->setController(OneOfYourCrudController::class)->generateUrl());

        // Option 2. You can make your dashboard redirect to different pages depending on the user
        //
        // if ('jane' === $this->getUser()->getUsername()) {
        //     return $this->redirectToRoute('...');
        // }

        // Option 3. You can render some custom template to display a proper dashboard with widgets, etc.
        // (tip: it's easier if your template extends from @EasyAdmin/page/content.html.twig)
        //
        // return $this->render('some/path/my-dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Wearbase');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToRoute('RAG-конвейер', 'fas fa-cogs', 'admin_rag');
        yield MenuItem::linkToRoute('RAG: бренд вручную', 'fas fa-hand-pointer', 'admin_rag_brand');
        yield MenuItem::linkToRoute('Верификация брендов', 'fas fa-circle-exclamation', 'admin_rag_review');
         yield MenuItem::linkToCrud('Brands', 'fas fa-list', Brand::class);

        yield MenuItem::section('Контент');
        yield MenuItem::linkToCrud('Статьи блога', 'fas fa-newspaper', Article::class);

        yield MenuItem::section('Dictionaries');
        yield MenuItem::linkToCrud('Размеры', 'fas fa-list', BrandSize::class);
        yield MenuItem::linkToCrud('Стили', 'fas fa-list', BrandStyle::class);
        yield MenuItem::linkToCrud('Аудитория', 'fas fa-list', BrandAudience::class);
        yield MenuItem::linkToCrud('Ценовой сегмент', 'fas fa-list', BrandTier::class);
        yield MenuItem::linkToCrud('Ссылки', 'fas fa-list', BrandLink::class);
        yield MenuItem::linkToCrud('Изображения', 'fas fa-list', BrandImage::class);
        yield MenuItem::section('Administration');
        yield MenuItem::linkToCrud('SectionType', 'fas fa-list', SectionType::class);
        yield MenuItem::linkToCrud('SectionLink', 'fas fa-list', SectionLink::class);
        yield MenuItem::linkToCrud('Крон (расписание)', 'fas fa-clock', ScheduledCommand::class);
        yield MenuItem::section('Заявки');
        yield MenuItem::linkToUrl('Заявки на бренды', 'fas fa-store',
            $this->generateUrl('admin', ['routeName' => 'admin_brand_claims']),
        );

        yield MenuItem::section('Международные рынки');
        yield MenuItem::linkToCrud('Языки', 'fas fa-language', Language::class);
        yield MenuItem::linkToCrud('Страны', 'fas fa-globe', Country::class);
        yield MenuItem::linkToCrud('Города', 'fas fa-city', City::class);
        yield MenuItem::linkToCrud('Валюты', 'fas fa-coins', Currency::class);
        yield MenuItem::linkToCrud('Курсы валют', 'fas fa-chart-line', ExchangeRate::class);
        yield MenuItem::linkToCrud('Правила доставки', 'fas fa-truck', ShippingRule::class);
        yield MenuItem::linkToCrud('Налоговые правила', 'fas fa-receipt', TaxRule::class);
        yield MenuItem::linkToCrud('Рынки брендов', 'fas fa-flag', BrandMarket::class);

        yield MenuItem::section('Пользователи');
        yield MenuItem::linkToCrud('Users', 'fas fa-users', User::class);
    }
}
