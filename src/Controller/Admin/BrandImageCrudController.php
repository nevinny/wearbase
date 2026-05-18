<?php

namespace App\Controller\Admin;

use App\Entity\BrandImage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class BrandImageCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrandImage::class;
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
