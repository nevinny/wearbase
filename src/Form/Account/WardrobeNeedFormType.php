<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeNeed;

class WardrobeNeedFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['subjects'] as $subject) {
            $choices[$subject->getFullName().' · №'.$subject->getId()] = $subject;
        }
        $builder
            ->add('subject', ChoiceType::class, ['label' => 'Для кого', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('category', EntityType::class, [
                'class' => WardrobeCategory::class,
                'label' => 'Тип вещи',
                'choice_label' => 'name',
                'placeholder' => 'Выберите тип вещи',
                'group_by' => static fn (WardrobeCategory $category): string => $category->getParent()?->getName() ?? 'Основные категории',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')
                    ->andWhere('c.isActive = true')->orderBy('c.sortOrder', 'ASC')->addOrderBy('c.name', 'ASC'),
                'constraints' => [new NotBlank()],
            ])
            ->add('season', ChoiceType::class, ['label' => 'Сезон', 'choices' => array_flip(WardrobeNeed::SEASONS), 'constraints' => [new NotBlank()]])
            ->add('title', TextType::class, ['label' => 'Что нужно купить', 'constraints' => [new NotBlank(), new Length(max: 150)]])
            ->add('quantity', IntegerType::class, ['label' => 'Количество', 'constraints' => [new NotBlank(), new Range(min: 1, max: 99)]])
            ->add('size', TextType::class, ['label' => 'Нужный размер', 'required' => false, 'constraints' => [new Length(max: 50)]])
            ->add('notes', TextareaType::class, ['label' => 'Комментарий', 'required' => false, 'constraints' => [new Length(max: 1000)], 'attr' => ['rows' => 3]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('subjects');
        $resolver->setAllowedTypes('subjects', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'wardrobe_need';
    }
}
