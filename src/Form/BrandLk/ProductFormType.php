<?php

declare(strict_types=1);

namespace App\Form\BrandLk;

use App\Entity\BrandStyle;
use App\Entity\Product;
use App\Entity\ProductCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ProductFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label'       => 'Название товара',
                'attr'        => ['placeholder' => 'Худи Basic'],
                'constraints' => [new NotBlank(['message' => 'Введите название'])],
            ])
            ->add('category', EntityType::class, [
                'class'        => ProductCategory::class,
                'choice_label' => 'title',
                'label'        => 'Категория',
                'placeholder'  => '— выберите категорию —',
                'required'     => false,
            ])
            ->add('gender', ChoiceType::class, [
                'label'   => 'Пол / аудитория',
                'choices' => [
                    'Мужской'  => Product::GENDER_MEN,
                    'Женский'  => Product::GENDER_WOMEN,
                    'Унисекс'  => Product::GENDER_UNISEX,
                    'Детский'  => Product::GENDER_KIDS,
                ],
                'expanded' => true,
                'required' => false,
            ])
            ->add('anons', TextareaType::class, [
                'label'    => 'Краткое описание',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Один-два предложения для карточки в каталоге'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Подробное описание',
                'required' => false,
                'attr'     => ['rows' => 6],
            ])
            ->add('styles', EntityType::class, [
                'class'        => BrandStyle::class,
                'choice_label' => 'title',
                'multiple'     => true,
                'expanded'     => true,
                'label'        => 'Стили',
                'required'     => false,
            ])
            ->add('metaTitle', TextType::class, [
                'label'    => 'Meta Title',
                'required' => false,
            ])
            ->add('metaDescription', TextareaType::class, [
                'label'    => 'Meta Description',
                'required' => false,
                'attr'     => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
