<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class PurchaseRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['subjects'] as $subject) {
            $choices[$subject->getFirstName() ?: $subject->getFullName()] = $subject;
        }

        $builder
            ->add('subject', ChoiceType::class, [
                'label' => 'Для кого',
                'choices' => $choices,
            ])
            ->add('productUrl', UrlType::class, [
                'label' => 'Ссылка на товар или подборку',
                'constraints' => [
                    new NotBlank(['message' => 'Вставьте ссылку']),
                    new Length(['max' => 2048]),
                    new Url(['protocols' => ['https'], 'message' => 'Используйте безопасную HTTPS-ссылку']),
                ],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'constraints' => [new Length(['max' => 2000])],
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
