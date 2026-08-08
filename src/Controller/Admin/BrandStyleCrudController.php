<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrandStyle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;
use Nevinny\AdminCoreBundle\Enum\Statuses;

class BrandStyleCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrandStyle::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Стиль')
            ->setEntityLabelInPlural('Стили')
            ->setSearchFields(['title', 'slug', 'description'])
            ->setDefaultSort(['ord' => 'ASC', 'title' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', 'Название')->setRequired(true);
        yield SlugField::new('slug', 'Slug')
            ->setTargetFieldName('title')
            ->setRequired(true);
        yield TextareaField::new('description', 'Описание страницы стиля')
            ->setNumOfRows(8)
            ->hideOnIndex();
        yield AssociationField::new('brands', 'Бренды')
            ->setFormTypeOption('by_reference', false)
            ->autocomplete()
            ->hideOnIndex();
        yield ChoiceField::new('status', 'Статус')
            ->setChoices(Statuses::cases())
            ->setFormTypeOption('choice_label', 'name')
            ->setFormTypeOption('choice_value', 'value');
        yield IntegerField::new('ord', 'Порядок');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('title')
            ->add('slug')
            ->add('status')
            ->add('brands');
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }
}
