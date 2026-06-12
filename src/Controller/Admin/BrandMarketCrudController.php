<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrandMarket;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class BrandMarketCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrandMarket::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $statuses = [
            'Активен'      => BrandMarket::STATUS_ACTIVE,
            'На паузе'     => BrandMarket::STATUS_PAUSED,
            'Скоро'        => BrandMarket::STATUS_COMING,
        ];

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('brand', 'Бренд');
        yield AssociationField::new('country', 'Страна');
        yield ChoiceField::new('status', 'Статус')->setChoices($statuses);
        yield BooleanField::new('hasLocalWarehouse', 'Локальный склад');
        yield NumberField::new('customShippingRub', 'Своя цена доставки (₽)')->setNumDecimals(2)->setRequired(false)
            ->setHelp('Если не задана — используется глобальное правило ShippingRule');
        yield NumberField::new('freeShippingFromRub', 'Бесплатно от (₽)')->setNumDecimals(2)->setRequired(false);
        yield IntegerField::new('estimatedDays', 'Срок доставки (дней)')->setRequired(false);
        yield DateField::new('activeFrom', 'Активен с')->setRequired(false);
        yield IntegerField::new('sortOrder', 'Порядок');
    }
}
