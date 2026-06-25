<?php

namespace App\Service\Social;

use App\Entity\SocialPost;
use App\Service\LlmService;

/**
 * Сборка подписи поста. Тон — честный/дерзкий (docs/marketing_strategy.md §5), без sterile-копии.
 *
 * Ядро-сообщения (template-рубрики) генерятся LLM на ПРИВЯЗАННЫХ фактах (grounding): факты,
 * цифры и угол подачи зашиты в PILLARS как константа (ноль выдумок про конкурента/комиссии),
 * а формулировку каждый раз пишет модель — отсюда разнообразие. Брендовые (llm-рубрики) —
 * из описания бренда. Структурные части (CTA-ссылка, хэштеги) добавляются детерминированно кодом.
 */
class CaptionGenerator
{
    /**
     * Пиллары ядра-сообщений: facts — единственный источник фактов для LLM (не выдумывать сверх),
     * angles — углы подачи (ротация по неделе/каналу гарантирует разный фрейм). marketing_strategy.md §5.
     *
     * @var array<string,array{facts:string,angles:string[]}>
     */
    private const PILLARS = [
        'manifesto' => [
            'facts' => 'Прямые (независимые) бренды продают напрямую покупателю, а не через маркетплейс. '
                . 'Маркетплейс превращает бренд в обезличенный артикул в выдаче, забирает себе отношения с покупателем и часть выручки. '
                . 'Прямой бренд сохраняет себе и клиентов, и маржу, и имя, и отвечает за вещь сам.',
            'angles' => [
                'манифест: мы прямые бренды, а не строчки в чужой выдаче',
                'противопоставление: на маркетплейсе ты артикул — у нас ты бренд',
                'отношения бренда с покупателем, которые маркетплейс сдаёт бренду же в аренду',
            ],
        ],
        'calculator' => [
            'facts' => 'Маркетплейсы (Wildberries, Ozon) удерживают с бренда комиссию 30–67% с каждой продажи. '
                . 'На WEARBASE комиссии с продаж нет (0%), бренд платит только фиксированную подписку (около 3000 ₽/мес). '
                . 'Комиссия растёт вместе с оборотом, фикс — нет. Других цифр не выдумывать.',
            'angles' => [
                'посчитай вслух: при обороте 300 000 ₽/мес площадка забрала бы до половины',
                'фикс против процента: чем больше оборот, тем выгоднее фикс',
                'риторический вопрос «сколько маркетплейс забирает лично у твоего бренда»',
            ],
        ],
        'vs_marketplace' => [
            'facts' => 'На маркетплейсе ту же вещь часто перепродаёт перекуп с наценкой, встречаются подделки, '
                . 'а цену задирают комиссия и реклама. У бренда напрямую — оригинал по честной цене от того, кто это сшил. '
                . 'Миф «на маркетплейсе всегда дешевле» в реальности не работает. Конкретных цифр не выдумывать.',
            'angles' => [
                'та же вещь у перекупа дороже — тут напрямую от бренда',
                'разоблачение мифа «на маркетплейсе дешевле»',
                'маркетплейс продаёт «куртку женскую оверсайз», бренд продаёт себя',
            ],
        ],
    ];

    public function __construct(
        private readonly LlmService $llm,
        private readonly string $siteBaseUrl,
    ) {
    }

    /**
     * Наполнить пост: подпись (тело банка/LLM + хэштеги, БЕЗ сырого URL) и CTA-поля
     * (label + ссылка с UTM). Публикаторы оформляют ссылку под площадку (TG — кликабельный
     * текст, VK — текст+URL, IG — без URL). aiGenerated проставляет вызывающий.
     */
    public function compose(SocialPost $post, array $rubricDef): void
    {
        $body = $rubricDef['source'] === SocialRubrics::SOURCE_LLM
            ? $this->llmBody($post)
            : $this->pillarBody($post->getRubric(), $this->rotationSeed($post));

        $tags = implode(' ', $rubricDef['hashtags']);
        $post->setCaption(trim($body) . "\n\n" . $tags);

        [$label, $url] = $this->cta($post);
        $post->setCtaLabel($label)->setCtaUrl($url);
    }

    /** Подпись ядра-сообщения: LLM пишет на привязанных фактах пиллара, угол подачи — по seed. */
    private function pillarBody(string $rubric, int $seed): string
    {
        $pillar = self::PILLARS[$rubric] ?? self::PILLARS['manifesto'];
        $angle = $pillar['angles'][$seed % count($pillar['angles'])];

        $system = 'Ты — SMM-копирайтер бренд-каталога WEARBASE. Пишешь по-русски, дерзко и по делу, '
            . 'без рекламных штампов и markdown. Отвечаешь только текстом подписи.';
        $prompt = <<<EOT
Факты (единственный источник, не добавляй других цифр и утверждений):
{$pillar['facts']}

Напиши свежую подпись для поста в соцсети на этом тезисе. Угол подачи: {$angle}.
2–4 коротких предложения, максимум 50 слов, на «ты».
Запрещено: выдумывать цифры/факты сверх данных, «инновационный», «уникальный», «передовой», «лидирующий», кавычки, markdown, ссылки, хэштеги.
Только текст подписи.
EOT;

        return trim($this->llm->generate($prompt, $system, local: true, think: false));
    }

    /**
     * Seed ротации угла подачи: номер ISO-недели (продвигается каждую неделю → другой угол)
     * + id канала (разные площадки в одну неделю получают разный фрейм). Детерминирован по слоту
     * → стабилен при ретраях (тот же угол, но текст модель пишет заново).
     */
    private function rotationSeed(SocialPost $post): int
    {
        $week = (int) ($post->getScheduledAt()?->format('W') ?? 0);
        $channel = (int) ($post->getChannel()?->getId() ?? 0);

        return $week + $channel;
    }

    /** Подпись брендовой рубрики из описания бренда (grounded, без выдумок). */
    private function llmBody(SocialPost $post): string
    {
        $brand = $post->getBrand();
        if ($brand === null) {
            throw new \RuntimeException('Брендовая рубрика без бренда: ' . $post->getRubric());
        }

        $name = (string) $brand->getTitle();
        $city = $brand->getCity();
        $desc = trim((string) ($brand->getDescription() ?: $brand->getAnons()));
        if ($desc === '') {
            throw new \RuntimeException("У бренда «{$name}» нет описания для подписи");
        }

        $cityCtx = $city ? " из города {$city}" : '';
        $system = 'Ты — SMM-копирайтер. Пишешь по-русски, живо и по делу, без рекламных штампов и без markdown. '
            . 'Отвечаешь только текстом подписи.';
        $prompt = <<<EOT
Бренд одежды «{$name}»{$cityCtx}. Описание (единственный источник фактов):

{$desc}

Напиши подпись для поста в соцсети о ЭТОМ бренде: 2–3 коротких предложения, максимум 45 слов.
Зацепи интересом «нашёл марку раньше всех», говори о бренде по сути из описания.
Запрещено: «инновационный», «уникальный», «передовой», «лидирующий», «выделяется», кавычки, markdown, ссылки, хэштеги.
Только текст подписи.
EOT;

        return trim($this->llm->generate($prompt, $system, local: true, think: false));
    }

    /**
     * CTA: текст-подпись + ссылка с UTM.
     * @return array{0:string,1:string} [label, url]
     */
    private function cta(SocialPost $post): array
    {
        $source = $post->getChannel()?->getPlatform() ?? 'social';
        $brand = $post->getBrand();

        if ($brand !== null && $brand->getSlug()) {
            return ['Бренд напрямую', $this->withUtm('/ru/brands/' . $brand->getSlug(), $source, $post->getRubric())];
        }

        return ['Каталог независимых русских брендов', $this->withUtm('/ru/', $source, $post->getRubric())];
    }

    /** Ссылка с UTM-метками — для отслеживания эффективности канала/рубрики в аналитике. */
    private function withUtm(string $path, string $source, string $rubric): string
    {
        $query = http_build_query([
            'utm_source'   => $source,                          // tg | vk | ig
            'utm_medium'   => 'social',
            'utm_campaign' => $rubric !== '' ? $rubric : 'social_auto',
        ]);

        return rtrim($this->siteBaseUrl, '/') . $path . '?' . $query;
    }
}
