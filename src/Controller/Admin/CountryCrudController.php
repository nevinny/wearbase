<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Country;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class CountryCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return Country::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->container->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Страна')
            ->setEntityLabelInPlural('Страны')
            ->setDefaultSort(['sortOrder' => 'ASC', 'nameRu' => 'ASC'])
            ->setSearchFields(['code', 'nameRu', 'nameEn', 'phoneCode']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('flagEmoji', '🏳')->hideOnForm()->setColumns(1);
        yield TextField::new('code', 'ISO alpha-2')
            ->setHelp('ISO 3166-1 alpha-2: RU, US, AE…')
            ->setColumns(2);
        yield TextField::new('code3', 'ISO alpha-3')
            ->setHelp('RUS, USA, ARE…')
            ->setColumns(2);
        yield TextField::new('nameRu', 'Название (рус.)')->setColumns(4);
        yield TextField::new('nameEn', 'Название (англ.)')->setColumns(4);
        yield TextField::new('phoneCode', 'Тел. код')
            ->setColumns(2)
            ->setHelp('+7, +1, +971…');
        yield TextField::new('flagEmoji', 'Emoji флаг')
            ->onlyOnForms()
            ->setColumns(2)
            ->setHelp('Генерируется автоматически из кода, можно задать вручную');
        yield ChoiceField::new('region', 'Регион')
            ->setChoices([
                'Европа'          => 'europe',
                'Азия'            => 'asia',
                'Ближний Восток'  => 'middle_east',
                'Северная Америка'=> 'americas',
                'Африка'          => 'africa',
                'Австралия/Океания'=>'oceania',
            ])
            ->setColumns(3)
            ->allowMultipleChoices(false);
        yield AssociationField::new('defaultCurrency', 'Валюта по умолчанию')
            ->setColumns(3);
        yield AssociationField::new('defaultLanguage', 'Язык по умолчанию')
            ->setColumns(3);
        yield BooleanField::new('isActive', 'Активна');
        yield IntegerField::new('sortOrder', 'Порядок')->setColumns(2);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('isActive')
            ->add('region')
            ->add('defaultCurrency');
    }
}
