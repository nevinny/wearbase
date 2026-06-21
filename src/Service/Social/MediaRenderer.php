<?php

namespace App\Service\Social;

use App\Entity\SocialPost;
use App\Service\LlmService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Картинка к посту. Дефолт — бесплатная генерация через Pollinations (без ключа, работает из РФ);
 * опционально Gemini «Nano Banana» (если задан GEMINI_API_KEY — качественнее, но Google
 * геоблокит РФ → нужен прокси/VPN). Фолбэк — логотип бренда → null (текстовый пост).
 *
 * Промпт — эстетичный визуал БЕЗ текста (длинный русский текст модели рисуют плохо;
 * смысл несёт подпись). См. docs/marketing_instagram.md §5.
 */
class MediaRenderer
{
    private const GEMINI_MODEL = 'gemini-2.5-flash-image';

    /** Базовые промпты по рубрикам (без текста). */
    private const PROMPTS = [
        'calculator'     => 'editorial flat lay, clothing price tags and a calculator on muted beige background, minimalist fashion photography, soft daylight',
        'manifesto'      => 'independent clothing brand atelier, sewing table, fabric rolls, warm authentic editorial photo, muted tones',
        'vs_marketplace' => 'a curated boutique clothing rack versus a faceless grey warehouse of identical boxes, editorial split scene, muted tones',
        'new_drops'      => 'modern russian streetwear flat lay, folded clothes and accessories, editorial fashion photography, neutral palette',
        'brand_week'     => 'minimalist fashion brand still life, clothing on a clean studio backdrop, editorial photography, soft light',
        'lifestyle'      => 'young person wearing stylish independent russian brand clothing, candid street style, editorial, natural light',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly LlmService $llm,
        private readonly string $projectDir,
        private readonly string $geminiApiKey = '',
        private readonly string $cfAccountId = '',
        private readonly string $cfApiToken = '',
    ) {
    }

    public function render(SocialPost $post): ?string
    {
        // Адаптивный промпт из текста поста (LLM на .119); при неудаче — статический per-rubric.
        $imagePrompt = $this->buildPrompt($post);
        $post->setImagePrompt($imagePrompt); // промежуточный результат → в запись (flush в generate-команде)

        // Случайный seed на каждую генерацию — иначе Pollinations (детерминирован по промпту)
        // и кеши отдают ОДНУ И ТУ ЖЕ картинку. С seed каждый пост — уникальный визуал.
        $bytes = $this->generate($imagePrompt, random_int(1, 2_000_000_000));
        if ($bytes !== null) {
            $saved = $this->save($post, $bytes);
            if ($saved !== null) {
                return $saved;
            }
        }

        // Фолбэк: существующий логотип бренда.
        $brand = $post->getBrand();
        $logo = $brand?->getLogo();
        if ($logo !== null && $logo !== '' && is_file($this->projectDir . '/public_html/images/logos/' . $logo)) {
            return '/images/logos/' . $logo;
        }

        return null; // текст-пост (TG/VK ок; IG уйдёт в held)
    }

    /** Адаптивный промпт из caption через LLM; при пустом тексте/ошибке — статический per-rubric. */
    private function buildPrompt(SocialPost $post): string
    {
        $caption = $post->getCaption();
        if ($caption !== null && trim($caption) !== '') {
            $adaptive = $this->llm->imagePromptFromCaption($caption, $post->getRubric());
            if ($adaptive !== null) {
                return $adaptive . '. No text, no letters, no logo, no watermark.';
            }
        }

        return $this->prompt($post);
    }

    private function prompt(SocialPost $post): string
    {
        $base = self::PROMPTS[$post->getRubric()] ?? 'minimalist editorial fashion photography, independent clothing brand, muted tones';

        // Релевантность брендовому посту: город + стиль бренда (slug латиницей: streetwear/minimalizm…),
        // чтобы визуал отражал конкретный бренд, а не только рубрику.
        $brand = $post->getBrand();
        if ($brand !== null) {
            if ($brand->getCity()) {
                $base .= ', vibe of ' . $brand->getCity();
            }
            $style = $brand->getStyles()->first();
            if ($style !== false && $style->getSlug()) {
                $base .= ', ' . str_replace('-', ' ', $style->getSlug()) . ' style';
            }
        }

        return $base . '. No text, no letters, no logo, no watermark.';
    }

    /**
     * Bytes картинки или null. Цепочка с фолбэком: Gemini (платный, если ключ) →
     * Cloudflare Flux (free, если креды) → Pollinations (free, без ключа). Первый успех выигрывает.
     */
    private function generate(string $prompt, int $seed): ?string
    {
        if (trim($this->geminiApiKey) !== '') {
            $bytes = $this->tryGemini($prompt); // Gemini рандомит сам, seed не нужен
            if ($bytes !== null) {
                return $bytes;
            }
        }

        if (trim($this->cfAccountId) !== '' && trim($this->cfApiToken) !== '') {
            $bytes = $this->tryCloudflare($prompt, $seed);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return $this->tryPollinations($prompt, $seed);
    }

    private function tryCloudflare(string $prompt, int $seed): ?string
    {
        try {
            $url = sprintf(
                'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/@cf/black-forest-labs/flux-1-schnell',
                $this->cfAccountId,
            );
            $resp = $this->httpClient->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->cfApiToken],
                'json'    => ['prompt' => $prompt, 'steps' => 8, 'seed' => $seed],
                'timeout' => 90,
            ]);
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            $data = $resp->toArray(false);
            $b64 = $data['result']['image'] ?? $data['image'] ?? null;
            if ($b64 !== null) {
                $decoded = base64_decode($b64, true);
                if ($decoded !== false && strlen($decoded) > 1024) {
                    return $decoded;
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Cloudflare Flux image gen failed: ' . $e->getMessage());
            return null;
        }
    }

    private function tryPollinations(string $prompt, int $seed): ?string
    {
        try {
            $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
                . '?width=1024&height=1024&nologo=true&model=flux&seed=' . $seed;
            $resp = $this->httpClient->request('GET', $url, ['timeout' => 90]);
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            $bytes = $resp->getContent(false);

            return strlen($bytes) > 1024 ? $bytes : null; // отсечь ошибки-заглушки
        } catch (\Throwable $e) {
            $this->logger->warning('Pollinations image gen failed: ' . $e->getMessage());
            return null;
        }
    }

    private function tryGemini(string $prompt): ?string
    {
        try {
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
                self::GEMINI_MODEL,
                $this->geminiApiKey,
            );
            $resp = $this->httpClient->request('POST', $url, [
                'json'    => ['contents' => [['parts' => [['text' => $prompt]]]]],
                'timeout' => 90,
            ]);
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            foreach ($resp->toArray(false)['candidates'][0]['content']['parts'] ?? [] as $part) {
                $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
                if ($data !== null) {
                    $decoded = base64_decode($data, true);
                    if ($decoded !== false && strlen($decoded) > 1024) {
                        return $decoded;
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Gemini image gen failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Сохранить картинку в public_html/images/social, вернуть public-относительный путь. */
    private function save(SocialPost $post, string $bytes): ?string
    {
        $dir = $this->projectDir . '/public_html/images/social';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $name = sprintf('%d-%s.png', (int) $post->getId(), substr(md5($bytes), 0, 8));
        if (@file_put_contents($dir . '/' . $name, $bytes) === false) {
            return null;
        }

        return '/images/social/' . $name;
    }
}
