<?php

namespace App\Controller\Admin;

use App\Entity\BrandTier;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class BrandTierCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrandTier::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = AbstractCrudController::configureActions($actions);

        // добавляем кастомные NEW, если нужно
        return $actions;
    }
}
