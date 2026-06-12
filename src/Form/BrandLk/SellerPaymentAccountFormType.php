<?php

declare(strict_types=1);

namespace App\Form\BrandLk;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Repository\PaymentProviderRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SellerPaymentAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('provider', EntityType::class, [
                'label'         => 'Платёжка',
                'class'         => PaymentProvider::class,
                'choice_label'  => 'name',
                'query_builder' => fn (PaymentProviderRepository $r) => $r->createQueryBuilder('p')
                    ->andWhere('p.isActive = true')
                    ->orderBy('p.sortOrder', 'ASC'),
            ])
            ->add('mode', ChoiceType::class, [
                'label'   => 'Схема приёма',
                'choices' => [
                    'Прямой эквайринг (деньги напрямую бренду)' => SellerPaymentAccount::MODE_DIRECT,
                    'Сплит маркетплейса'                        => SellerPaymentAccount::MODE_MARKETPLACE,
                ],
            ])
            ->add('accountRef', TextType::class, [
                'label'    => 'Идентификатор магазина (shopId / account_id)',
                'required' => false,
            ])
            // Не маппится: секрет шифруется в контроллере. На редактировании можно оставить пустым.
            ->add('secret', PasswordType::class, [
                'label'       => 'Секретный ключ',
                'required'    => false,
                'mapped'      => false,
                'attr'        => ['autocomplete' => 'new-password'],
                'help'        => 'Оставьте пустым, чтобы не менять сохранённый ключ',
            ])
            ->add('isPrimary', CheckboxType::class, [
                'label'    => 'Основной счёт приёма',
                'required' => false,
            ])
            ->add('status', ChoiceType::class, [
                'label'   => 'Статус',
                'choices' => [
                    'Активен'   => SellerPaymentAccount::STATUS_ACTIVE,
                    'Отключён'  => SellerPaymentAccount::STATUS_DISABLED,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SellerPaymentAccount::class]);
    }
}
