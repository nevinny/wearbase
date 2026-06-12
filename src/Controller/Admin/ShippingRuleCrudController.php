<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ShippingRule;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class ShippingRuleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ShippingRule::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $carriers = [
            'Курьер'         => ShippingRule::CARRIER_COURIER,
            'СДЭК'           => ShippingRule::CARRIER_CDEK,
            'Boxberry'       => ShippingRule::CARRIER_BOXBERRY,
            'Почта России'   => ShippingRule::CARRIER_POCHTA,
            'DHL'            => ShippingRule::CARRIER_DHL,
            'FedEx'          => ShippingRule::CARRIER_FEDEX,
            'Самовывоз'      => ShippingRule::CARRIER_PICKUP,
        ];

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('country', 'Страна');
        yield ChoiceField::new('carrier', 'Перевозчик')->setChoices($carriers);
        yield TextField::new('name', 'Название');
        yield NumberField::new('priceRub', 'Цена (₽)')->setNumDecimals(2);
        yield IntegerField::new('daysMin', 'Мин. дней');
        yield IntegerField::new('daysMax', 'Макс. дней');
        yield NumberField::new('maxWeightKg', 'Макс. вес (кг)')->setNumDecimals(2)->setRequired(false);
        yield NumberField::new('freeFromRub', 'Бесплатно от (₽)')->setNumDecimals(2)->setRequired(false);
        yield UrlField::new('trackingUrl', 'Ссылка отслеживания')->setRequired(false)
            ->setHelp('Используйте %s вместо номера отслеживания');
        yield BooleanField::new('isActive', 'Активно');
        yield IntegerField::new('sortOrder', 'Порядок');
    }
}
