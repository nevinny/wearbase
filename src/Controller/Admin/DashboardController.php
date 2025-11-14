<?php

namespace App\Controller\Admin;

use App\Entity\Brand;
use App\Entity\BrandAudience;
use App\Entity\BrandSize;
use App\Entity\BrandStyle;
use App\Entity\BrandTier;
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
         yield MenuItem::linkToCrud('Brands', 'fas fa-list', Brand::class);

        yield MenuItem::section('Dictionaries');
        yield MenuItem::linkToCrud('BrandSizes', 'fas fa-list', BrandSize::class);
        yield MenuItem::linkToCrud('BrandStyles', 'fas fa-list', BrandStyle::class);
        yield MenuItem::linkToCrud('BrandAudiences', 'fas fa-list', BrandAudience::class);
        yield MenuItem::linkToCrud('BrandATiers', 'fas fa-list', BrandTier::class);
        yield MenuItem::section('Administration');
        yield MenuItem::linkToCrud('SectionType', 'fas fa-list', SectionType::class);
        yield MenuItem::linkToCrud('SectionLink', 'fas fa-list', SectionLink::class);
        yield MenuItem::section('Пользователи');
        yield MenuItem::linkToCrud('Users', 'fas fa-users', User::class);
    }
}
