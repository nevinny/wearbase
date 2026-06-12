<?php

declare(strict_types=1);

namespace App\Form\BrandLk;

use App\Entity\SellerLegalEntity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SellerLegalEntityFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('legalForm', ChoiceType::class, [
                'label'   => 'Форма',
                'choices' => [
                    'ООО'         => SellerLegalEntity::FORM_OOO,
                    'ИП'          => SellerLegalEntity::FORM_IP,
                    'Самозанятый' => SellerLegalEntity::FORM_SELF_EMPLOYED,
                ],
            ])
            ->add('legalName', TextType::class, [
                'label' => 'Полное наименование / ФИО',
                'attr'  => ['placeholder' => 'ООО «Мой бренд» / ИП Иванов И.И.'],
            ])
            ->add('inn', TextType::class, [
                'label'    => 'ИНН',
                'required' => false,
            ])
            ->add('kpp', TextType::class, [
                'label'    => 'КПП',
                'required' => false,
            ])
            ->add('ogrn', TextType::class, [
                'label'    => 'ОГРН / ОГРНИП',
                'required' => false,
            ])
            ->add('legalAddress', TextareaType::class, [
                'label'    => 'Юридический адрес',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('effectiveFrom', DateType::class, [
                'label'    => 'Действует с',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('effectiveTo', DateType::class, [
                'label'    => 'Действует по',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'help'     => 'Пусто — действует сейчас',
            ])
            ->add('status', ChoiceType::class, [
                'label'   => 'Статус',
                'choices' => [
                    'Активно'   => SellerLegalEntity::STATUS_ACTIVE,
                    'В архиве'  => SellerLegalEntity::STATUS_ARCHIVED,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SellerLegalEntity::class]);
    }
}
