<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Vich\UploaderBundle\Form\Type\VichImageType;

class WardrobeItemFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('remotePhotoUrl', HiddenType::class, ['mapped' => false])
            ->add('photoFile', VichImageType::class, [
                'label' => 'Фото',
                'required' => false,
                'allow_delete' => true,
                'download_uri' => false,
            ])
            ->add('productUrl', UrlType::class, [
                'label' => 'Ссылка на товар',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('size', TextType::class, [
                'label' => 'Размер',
                'required' => false,
            ])
            ->add('price', NumberType::class, [
                'label' => 'Стоимость, ₽',
                'required' => false,
                'scale' => 2,
                'constraints' => [new GreaterThanOrEqual(0)],
            ])
            ->add('purchasedAt', DateType::class, [
                'label' => 'Дата покупки',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('purchaseReason', TextareaType::class, [
                'label' => 'Задача покупки',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);

        if (!$options['full']) {
            return;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'required' => false,
                'constraints' => [new Length(['max' => 255])],
            ])
            ->add('categoryRef', EntityType::class, [
                'class' => WardrobeCategory::class,
                'label' => 'Категория',
                'required' => false,
                'placeholder' => 'Выберите категорию',
                'choice_label' => 'name',
                'group_by' => static fn (WardrobeCategory $category): string => $category->getParent()?->getName() ?? 'Основные категории',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('category')
                    ->andWhere('category.isActive = true')
                    ->orderBy('category.sortOrder', 'ASC')
                    ->addOrderBy('category.name', 'ASC'),
            ])
            ->add('customBrandName', TextType::class, ['label' => 'Бренд', 'required' => false])
            ->add('colorName', TextType::class, ['label' => 'Цвет', 'required' => false])
            ->add('materialText', TextareaType::class, ['label' => 'Состав / материал', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('season', ChoiceType::class, [
                'label' => 'Сезон',
                'required' => false,
                'placeholder' => '—',
                'choices' => ['Всесезон' => 'all', 'Весна' => 'spring', 'Лето' => 'summer', 'Осень' => 'autumn', 'Зима' => 'winter'],
            ])
            ->add('countryOfOrigin', TextType::class, ['label' => 'Страна производства', 'required' => false])
            ->add('careText', TextareaType::class, ['label' => 'Уход', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('notes', TextareaType::class, ['label' => 'Заметки', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('itemStatus', ChoiceType::class, [
                'label' => 'Статус вещи',
                'choices' => array_flip(WardrobeItem::ITEM_LABELS),
            ])
            ->add('pros', TextareaType::class, ['label' => 'Плюсы', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('cons', TextareaType::class, ['label' => 'Минусы', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('verdict', TextareaType::class, ['label' => 'Вердикт', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WardrobeItem::class,
            'full' => true,
        ]);
        $resolver->setAllowedTypes('full', 'bool');
    }
}
