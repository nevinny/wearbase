<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Language;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class LanguageCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return Language::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->container->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Язык')
            ->setEntityLabelInPlural('Языки')
            ->setDefaultSort(['sortOrder' => 'ASC', 'code' => 'ASC'])
            ->setSearchFields(['code', 'nativeName', 'nameRu']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'ISO код')
            ->setHelp('ISO 639-1: ru, en, zh, ar…')
            ->setColumns(2);
        yield TextField::new('nativeName', 'Название (родной)')->setColumns(4);
        yield TextField::new('nameRu', 'Название (рус.)')->setColumns(4);
        yield ChoiceField::new('textDirection', 'Направление')
            ->setChoices(['Слева направо (LTR)' => 'ltr', 'Справа налево (RTL)' => 'rtl'])
            ->setColumns(2);
        yield BooleanField::new('isActive', 'Активен');
        yield BooleanField::new('isDefault', 'По умолчанию')
            ->setHelp('Только один язык может быть дефолтным');
        yield IntegerField::new('sortOrder', 'Порядок')->setColumns(2);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('isActive')
            ->add('isDefault')
            ->add('textDirection');
    }
}
