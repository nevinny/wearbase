<?php

declare(strict_types=1);

namespace App\Form\BrandLk;

use App\Entity\BrandLink;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Форма ссылки бренда (ЛК). Типы — фиксированный список из докблока
 * App\Entity\BrandLink::$linkType (свободная строка в сущности, но выбор
 * пользователя ограничен ChoiceType — не свободный текст).
 */
class BrandLinkFormType extends AbstractType
{
    private const LINK_TYPES = [
        'Сайт'      => 'website',
        'Instagram' => 'instagram',
        'VK'        => 'vk',
        'Telegram'  => 'telegram',
        'YouTube'   => 'youtube',
        'TikTok'    => 'tiktok',
        'Другое'    => 'other',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('linkType', ChoiceType::class, [
                'label'   => 'Тип ссылки',
                'choices' => self::LINK_TYPES,
            ])
            ->add('linkUrl', UrlType::class, [
                'label'            => 'Ссылка',
                // null — не достраивать протокол автоматически: строка без схемы должна
                // быть отклонена валидацией, а не тихо превращена в https://строка.
                'default_protocol' => null,
                'constraints'      => [
                    new NotBlank(message: 'Укажите ссылку.'),
                    new Length(max: 255),
                    // requireTld: false — сохраняем текущее (pre-8.0) поведение явно, без deprecation-предупреждения.
                    new Url(protocols: ['http', 'https'], requireTld: false),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BrandLink::class]);
    }
}
