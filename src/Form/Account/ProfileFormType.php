<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Vich\UploaderBundle\Form\Type\VichImageType;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Имя'])
            ->add('lastName',  TextType::class, ['label' => 'Фамилия', 'required' => false])
            ->add('email',     EmailType::class, ['label' => 'Email'])
            ->add('phone',     TelType::class,   ['label' => 'Телефон', 'required' => false])
            ->add('avatarFile', VichImageType::class, [
                'label'        => 'Аватар',
                'required'     => false,
                'allow_delete' => true,
                'download_uri' => false,
            ])
            ->add('newPassword', PasswordType::class, [
                'label'       => 'Новый пароль',
                'mapped'      => false,
                'required'    => false,
                'attr'        => ['autocomplete' => 'new-password'],
                'constraints' => [new Length(['min' => 8, 'minMessage' => 'Минимум {{ limit }} символов'])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
