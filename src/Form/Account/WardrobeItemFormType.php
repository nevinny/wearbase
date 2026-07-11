<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\WardrobeItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Vich\UploaderBundle\Form\Type\VichImageType;

class WardrobeItemFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', TextType::class, [
                'label'       => 'Категория',
                'constraints' => [new NotBlank(), new Length(['max' => 100])],
                'attr'        => ['list' => 'wardrobe-categories'],
            ])
            ->add('name', TextType::class, [
                'label'       => 'Название',
                'constraints' => [new NotBlank(), new Length(['max' => 255])],
            ])
            ->add('size', TextType::class, [
                'label'    => 'Размер',
                'required' => false,
            ])
            ->add('price', NumberType::class, [
                'label'       => 'Стоимость, ₽',
                'required'    => false,
                'scale'       => 2,
                'constraints' => [new GreaterThanOrEqual(0)],
            ])
            ->add('purchasedAt', DateType::class, [
                'label'    => 'Дата покупки',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('productUrl', UrlType::class, [
                'label'            => 'Ссылка на товар',
                'required'         => false,
                'default_protocol' => 'https',
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'Заметки',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('purchaseReason', TextareaType::class, [
                'label'    => 'Почему купил(а)',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('loveAtFirstSight', ChoiceType::class, [
                'label'       => 'Любовь с первого взгляда',
                'required'    => false,
                'placeholder' => '—',
                'choices'     => [
                    'Да'           => WardrobeItem::LOVE_YES,
                    'Нет'          => WardrobeItem::LOVE_NO,
                    'Пока не знаю' => WardrobeItem::LOVE_UNKNOWN,
                ],
            ])
            ->add('pros', TextareaType::class, [
                'label'    => 'Плюсы',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('cons', TextareaType::class, [
                'label'    => 'Минусы',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('verdict', TextareaType::class, [
                'label'    => 'Вердикт',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('photoFile', VichImageType::class, [
                'label'        => 'Фото',
                'required'     => false,
                'allow_delete' => true,
                'download_uri' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WardrobeItem::class]);
    }
}
