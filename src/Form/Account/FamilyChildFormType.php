<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Dto\Family\ChildProfileInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Добавление managed-ребёнка в семью (без email — User создаёт FamilyService::createChild).
 */
class FamilyChildFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label'       => 'Имя',
                'attr'        => ['placeholder' => 'Маша'],
                'constraints' => [
                    new NotBlank(['message' => 'Введите имя']),
                    new Length(['max' => 100]),
                ],
            ])
            ->add('birthDate', DateType::class, [
                'label'    => 'Дата рождения',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Фамилия',
                'required' => false,
            ])
            ->add('gender', ChoiceType::class, [
                'label' => 'Пол',
                'required' => false,
                'placeholder' => 'Не указывать',
                'choices' => [
                    'Девочка' => 'girl',
                    'Мальчик' => 'boy',
                    'Предпочитаем не указывать' => 'prefer_not_to_say',
                ],
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Аватар',
                'required' => false,
            ])
            ->add('heightCm', IntegerType::class, [
                'label' => 'Рост, см',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 40, 'max' => 250],
            ])
            ->add('clothingSize', TextType::class, [
                'label' => 'Размер одежды',
                'required' => false,
                'attr' => ['placeholder' => 'Например, 158 или S'],
            ])
            ->add('shoeSize', TextType::class, [
                'label' => 'Размер обуви',
                'required' => false,
                'attr' => ['placeholder' => 'Например, 38'],
            ])
            ->add('profileNotes', TextareaType::class, [
                'label' => 'Предпочтения',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'Любимые цвета, стили, что не любит носить'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChildProfileInput::class]);
    }
}
