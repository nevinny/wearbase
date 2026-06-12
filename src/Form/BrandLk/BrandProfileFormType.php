<?php

declare(strict_types=1);

namespace App\Form\BrandLk;

use App\Entity\Brand;
use App\Entity\BrandAudience;
use App\Entity\BrandStyle;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

class BrandProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Основное
            ->add('title', TextType::class, [
                'label' => 'Название бренда',
                'attr'  => ['placeholder' => 'Мой бренд'],
            ])
            ->add('anons', TextareaType::class, [
                'label'    => 'Краткое описание',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => 'Пара предложений о бренде — для карточки в каталоге'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Подробное описание',
                'required' => false,
                'attr'     => ['rows' => 6],
            ])
            ->add('city', TextType::class, [
                'label'    => 'Город',
                'required' => false,
                'attr'     => ['placeholder' => 'Москва'],
            ])
            // Логотип
            ->add('logoFile', VichImageType::class, [
                'label'         => 'Логотип',
                'required'      => false,
                'allow_delete'  => true,
                'download_uri'  => false,
                'image_uri'     => true,
            ])
            // Контакты
            ->add('email', EmailType::class, [
                'label'    => 'Email для связи',
                'required' => false,
            ])
            ->add('phone', TelType::class, [
                'label'    => 'Телефон',
                'required' => false,
                'attr'     => ['placeholder' => '+7 999 000 00 00'],
            ])
            ->add('address', TextType::class, [
                'label'    => 'Адрес шоурума',
                'required' => false,
                'attr'     => ['placeholder' => 'Москва, ул. Пример, д. 1'],
            ])
            // Справочники
            ->add('styles', EntityType::class, [
                'class'        => BrandStyle::class,
                'choice_label' => 'title',
                'multiple'     => true,
                'expanded'     => true,
                'label'        => 'Стили',
                'required'     => false,
            ])
            ->add('audiences', EntityType::class, [
                'class'        => BrandAudience::class,
                'choice_label' => 'title',
                'multiple'     => true,
                'expanded'     => true,
                'label'        => 'Аудитория',
                'required'     => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Brand::class]);
    }
}
