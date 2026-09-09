<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PurchaseRequestFormType extends AbstractType
{
    /** Ссылка внутри вставленного текста («Название товара https://…» из шеринга WB/Ozon). */
    private const PASTE_URL_PATTERN = '~https?://[^\s<>"\']+~iu';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['subjects'] as $subject) {
            $choices[$subject->getFirstName() ?: $subject->getFullName()] = $subject;
        }

        // Нормализуем вставку «текст + ссылка» ДО трансформеров и валидации.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }
            if (isset($data['productUrl']) && \is_string($data['productUrl'])) {
                $data['productUrl'] = self::extractFirstUrl($data['productUrl']);
            }
            if (isset($data['additionalUrls']) && \is_string($data['additionalUrls'])) {
                $lines = preg_split('/\R/', $data['additionalUrls']) ?: [];
                $data['additionalUrls'] = implode("\n", array_map(self::extractFirstUrl(...), $lines));
            }
            $event->setData($data);
        });

        $builder
            ->add('subject', ChoiceType::class, [
                'label' => 'Для кого',
                'choices' => $choices,
            ])
            ->add('productUrl', UrlType::class, [
                'label' => 'Первая ссылка на товар',
                'constraints' => [
                    new NotBlank(['message' => 'Вставьте ссылку']),
                    new Length(['max' => 2048]),
                    new Url(['protocols' => ['https'], 'message' => 'Используйте безопасную HTTPS-ссылку']),
                ],
            ])
            ->add('additionalUrls', TextareaType::class, [
                'label' => 'Ещё ссылки',
                'required' => false,
                'help' => 'Каждая ссылка с новой строки. Всего можно добавить до 10 вещей.',
                'attr' => ['rows' => 4, 'placeholder' => "https://shop.example/item-2\nhttps://shop.example/item-3"],
                'constraints' => [
                    new Length(['max' => 18432]),
                    new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        $urls = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: [])));
                        if (count($urls) > 9) {
                            $context->buildViolation('В одном запросе можно до 10 вещей.')->addViolation();
                            return;
                        }
                        foreach ($urls as $url) {
                            try {
                                \App\Entity\PurchaseRequest::assertSafeProductUrl($url);
                            } catch (\InvalidArgumentException) {
                                $context->buildViolation('Каждая строка должна содержать безопасную HTTPS-ссылку.')->addViolation();
                                return;
                            }
                        }
                    }),
                ],
            ])
            ->add('estimatedPrice', MoneyType::class, [
                'label' => 'Ожидаемая цена',
                'currency' => 'RUB',
                'divisor' => 1,
                'input' => 'string',
                'scale' => 2,
                'required' => false,
                'constraints' => [new GreaterThanOrEqual(0)],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'constraints' => [new Length(['max' => 2000])],
            ]);
    }

    /** Вытаскиваем первый URL из строки; если ссылки нет — строка остаётся как была (валидация покажет ошибку). */
    private static function extractFirstUrl(string $value): string
    {
        return preg_match(self::PASTE_URL_PATTERN, $value, $m) === 1 ? $m[0] : $value;
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
