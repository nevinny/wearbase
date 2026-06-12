<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\Address;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class AddressFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label'    => 'Название адреса',
                'required' => false,
                'attr'     => ['placeholder' => 'Домашний / Рабочий'],
            ])
            ->add('fullName', TextType::class, [
                'label'       => 'Получатель',
                'constraints' => [new NotBlank()],
            ])
            ->add('phone', TelType::class, [
                'label'       => 'Телефон',
                'constraints' => [new NotBlank()],
            ])
            ->add('city', TextType::class, [
                'label'       => 'Город',
                'constraints' => [new NotBlank()],
            ])
            ->add('street', TextType::class, [
                'label'       => 'Улица',
                'constraints' => [new NotBlank()],
            ])
            ->add('building', TextType::class, ['label' => 'Дом', 'required' => false])
            ->add('apartment', TextType::class, ['label' => 'Квартира', 'required' => false])
            ->add('zip', TextType::class, [
                'label'    => 'Индекс',
                'required' => false,
                'attr'     => ['placeholder' => '101000'],
            ])
            ->add('isDefault', CheckboxType::class, [
                'label'    => 'Использовать по умолчанию',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Address::class]);
    }
}
