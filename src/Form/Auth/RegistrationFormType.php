<?php

declare(strict_types=1);

namespace App\Form\Auth;

use App\Entity\User;
use PixelOpen\CloudflareTurnstileBundle\Type\TurnstileType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label'       => 'Имя',
                'attr'        => ['placeholder' => 'Иван'],
                'constraints' => [
                    new NotBlank(['message' => 'Введите имя']),
                    new Length(['max' => 100]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr'  => ['placeholder' => 'you@example.com'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'           => PasswordType::class,
                'mapped'         => false,
                'first_options'  => ['label' => 'Пароль', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Повторите пароль'],
                'constraints'    => [
                    new NotBlank(['message' => 'Введите пароль']),
                    new Length(['min' => 8, 'minMessage' => 'Пароль должен быть не менее {{ limit }} символов']),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label'       => false,
                'mapped'      => false,
                'constraints' => [
                    new IsTrue(['message' => 'Необходимо согласие на обработку персональных данных']),
                ],
            ])
            ->add('turnstile', TurnstileType::class, [
                'label' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
