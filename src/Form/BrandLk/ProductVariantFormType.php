<?php

declare(strict_types=1);

namespace App\Form\BrandLk;

use App\Entity\ProductVariant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

class ProductVariantFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('size', TextType::class, [
                'label'    => 'Размер',
                'required' => false,
                'attr'     => ['placeholder' => 'XS / S / M / L / XL'],
            ])
            ->add('color', TextType::class, [
                'label'    => 'Цвет',
                'required' => false,
                'attr'     => ['placeholder' => 'Чёрный'],
            ])
            ->add('colorHex', TextType::class, [
                'label'    => 'HEX цвет',
                'required' => false,
                'attr'     => ['type' => 'color', 'style' => 'width:60px; height:38px; padding:2px'],
            ])
            ->add('price', MoneyType::class, [
                'label'       => 'Цена',
                'currency'    => 'RUB',
                'attr'        => ['placeholder' => '4900'],
                'constraints' => [
                    new NotBlank(['message' => 'Укажите цену']),
                    new Positive(['message' => 'Цена должна быть положительной']),
                ],
            ])
            ->add('comparePrice', MoneyType::class, [
                'label'    => 'Старая цена',
                'currency' => 'RUB',
                'required' => false,
                'attr'     => ['placeholder' => '5900'],
            ])
            ->add('stockQty', IntegerType::class, [
                'label'    => 'Остаток',
                'attr'     => ['placeholder' => '10', 'min' => 0],
                'data'     => 0,
            ])
            ->add('sku', TextType::class, [
                'label'    => 'Артикул (SKU)',
                'required' => false,
                'attr'     => ['placeholder' => 'BRAND-001-M-BLK'],
            ])
            ->add('weight', IntegerType::class, [
                'label'    => 'Вес (г)',
                'required' => false,
                'attr'     => ['placeholder' => '350'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductVariant::class]);
    }
}
