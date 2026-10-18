<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeNeed;

final class WardrobeClassificationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'class' => WardrobeCategory::class,
                'choices' => $options['categories'],
                'label' => 'Тип вещи',
                'choice_label' => 'name',
                'placeholder' => 'Выберите тип вещи',
                'group_by' => static fn (WardrobeCategory $category): string => $category->getParent()?->getName() ?? 'Основные категории',
                'constraints' => [new NotBlank()],
            ])
            ->add('season', ChoiceType::class, [
                'label' => 'Сезон',
                'choices' => array_flip(WardrobeNeed::SEASONS),
                'placeholder' => 'Выберите сезон',
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('categories');
        $resolver->setAllowedTypes('categories', 'array');
    }
}
