<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
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
            ]);
    }
}
