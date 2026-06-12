<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TaxRule;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class TaxRuleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TaxRule::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $types = [
            'НДС (VAT)'              => TaxRule::TYPE_VAT,
            'GST'                    => TaxRule::TYPE_GST,
            'Sales Tax'              => TaxRule::TYPE_SALES,
            'Таможенная пошлина'     => TaxRule::TYPE_CUSTOMS,
            'Без налога'             => TaxRule::TYPE_NONE,
        ];

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('country', 'Страна');
        yield TextField::new('name', 'Название');
        yield ChoiceField::new('taxType', 'Тип налога')->setChoices($types);
        yield NumberField::new('rate', 'Ставка (%)')->setNumDecimals(2)
            ->setHelp('НДС/GST/Sales Tax в процентах, например 20.00');
        yield NumberField::new('customsRate', 'Тамож. пошлина (%)')->setNumDecimals(2);
        yield NumberField::new('customsThresholdRub', 'Порог пошлин (₽)')->setNumDecimals(2)->setRequired(false)
            ->setHelp('До этой суммы пошлины не взимаются');
        yield BooleanField::new('isInclusive', 'Налог включён в цену');
        yield BooleanField::new('appliesToB2c', 'Для физлиц');
        yield BooleanField::new('appliesToB2b', 'Для юрлиц');
        yield UrlField::new('sourceUrl', 'Источник (URL)')->setRequired(false);
        yield BooleanField::new('isActive', 'Активно');
        yield IntegerField::new('sortOrder', 'Порядок');
    }
}
