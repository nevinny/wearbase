<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\City;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class CityCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return City::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->container->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Город')
            ->setEntityLabelInPlural('Города')
            ->setDefaultSort(['population' => 'DESC', 'nameRu' => 'ASC'])
            ->setSearchFields(['nameRu', 'nameEn', 'region']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('country', 'Страна')
            ->setColumns(3);
        yield TextField::new('nameRu', 'Название (рус.)')->setColumns(4);
        yield TextField::new('nameEn', 'Название (англ.)')->setColumns(4);
        yield TextField::new('region', 'Регион/Область')->setColumns(4);
        yield NumberField::new('latitude', 'Широта')
            ->setColumns(2)
            ->setNumDecimals(7);
        yield NumberField::new('longitude', 'Долгота')
            ->setColumns(2)
            ->setNumDecimals(7);
        yield IntegerField::new('population', 'Население')
            ->setColumns(3)
            ->setHelp('Используется для сортировки в автодополнении');
        yield BooleanField::new('isActive', 'Активен');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('country')
            ->add('isActive');
    }
}
