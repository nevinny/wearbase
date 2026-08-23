<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

class FamilyBudgetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['subjects'] as $subject) {
            $choices[$subject->getFirstName() ?: $subject->getFullName()] = $subject;
        }

        $builder
            ->add('subject', ChoiceType::class, [
                'label' => 'Ребёнок',
                'choices' => $choices,
            ])
            ->add('monthlyLimit', MoneyType::class, [
                'label' => 'Лимит на месяц',
                'currency' => 'RUB',
                'divisor' => 1,
                'input' => 'string',
                'scale' => 2,
                'constraints' => [
                    new NotBlank(),
                    new GreaterThanOrEqual(0),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('subjects');
        $resolver->setAllowedTypes('subjects', 'array');
        $resolver->setAllowedValues('subjects', static function (array $subjects): bool {
            foreach ($subjects as $subject) {
                if (!$subject instanceof User) {
                    return false;
                }
            }

            return true;
        });
    }
}
