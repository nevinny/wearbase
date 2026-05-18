<?php

namespace App\Controller\Admin;

use App\Entity\BrandLink;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class BrandLinkCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrandLink::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = AbstractCrudController::configureActions($actions);

        // добавляем кастомные NEW, если нужно
        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('title')
            ->add('status')
            ->add('brand')
            ;
    }
}
