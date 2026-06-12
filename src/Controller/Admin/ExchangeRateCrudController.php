<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ExchangeRate;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class ExchangeRateCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return ExchangeRate::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->container->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Курс валюты')
            ->setEntityLabelInPlural('Курсы валют')
            ->setDefaultSort(['rateDate' => 'DESC', 'baseCurrency' => 'ASC'])
            ->setHelp('index', '1 базовая валюта = rate целевых. Обновляйте курсы командой: php bin/console app:currency:update-rates');
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('baseCurrency', 'Базовая валюта')
            ->setColumns(3)
            ->setHelp('Обычно RUB');
        yield AssociationField::new('targetCurrency', 'Целевая валюта')
            ->setColumns(3);
        yield NumberField::new('rate', 'Курс')
            ->setColumns(3)
            ->setNumDecimals(8)
            ->setHelp('1 базовая = rate целевых. Пример: 1 RUB = 0.01083 USD');
        yield DateField::new('rateDate', 'Дата')->setColumns(3);
        yield ChoiceField::new('source', 'Источник')
            ->setChoices([
                'ЦБ РФ (cbr.ru)' => 'cbr',
                'Fixer.io'       => 'fixer',
                'Вручную'        => 'manual',
                'Начальные данные'=>'seed',
            ])
            ->setColumns(3);
        yield DateTimeField::new('updatedAt', 'Обновлено')
            ->hideOnForm()
            ->setColumns(3);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('baseCurrency')
            ->add('targetCurrency')
            ->add('source')
            ->add('rateDate');
    }
}
