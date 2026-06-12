<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Currency;
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

class CurrencyCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return Currency::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->container->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Валюта')
            ->setEntityLabelInPlural('Валюты')
            ->setDefaultSort(['code' => 'ASC'])
            ->setSearchFields(['code', 'nameRu', 'nameEn', 'symbol']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'ISO код')
            ->setHelp('ISO 4217: RUB, USD, EUR…')
            ->setColumns(2);
        yield TextField::new('symbol', 'Символ')
            ->setHelp('₽, $, €, ¥')
            ->setColumns(2);
        yield ChoiceField::new('symbolPosition', 'Позиция символа')
            ->setChoices(['Суффикс (99 ₽)' => 'suffix', 'Префикс ($99)' => 'prefix'])
            ->setColumns(2);
        yield TextField::new('nameRu', 'Название (рус.)')->setColumns(4);
        yield TextField::new('nameEn', 'Название (англ.)')->setColumns(4);
        yield IntegerField::new('decimalPlaces', 'Знаков после запятой')
            ->setColumns(2)
            ->setHelp('0 для JPY, 2 для большинства, 3 для KWD');
        yield TextField::new('decimalSeparator', 'Разд. дробной части')
            ->setColumns(2);
        yield TextField::new('thousandsSeparator', 'Разд. тысяч')
            ->setColumns(2);
        yield BooleanField::new('isBase', 'Базовая')
            ->setHelp('Только одна валюта может быть базовой (обычно RUB)');
        yield BooleanField::new('isActive', 'Активна');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('isBase')
            ->add('isActive');
    }
}
