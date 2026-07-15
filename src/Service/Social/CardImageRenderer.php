<?php

namespace App\Service\Social;

use App\Entity\SocialPost;

/**
 * Брендированная карточка (без AI-сцены) для рубрик-шаблонов: тёмный фон + заголовок
 * (первая строка уже сгенерированной подписи) + вотермарк WEARBASE. Не заменяет
 * MediaRenderer — используется точечно (см. SocialGenerateCommand) там, где свободная
 * AI-сцена для этих рубрик даёт слабую картинку для IG (docs/marketing_instagram.md §5).
 */
class CardImageRenderer
{
    private const SIZE = 1080;
    private const PADDING = 90;
    private const FONT_SIZE = 58;
    private const LINE_HEIGHT = 72;
    private const MAX_HEADLINE_CHARS = 220;

    /** Рубрики-шаблоны (SOURCE_TEMPLATE, без привязки к бренду) — годятся под общую цитата-карточку. */
    public const SUPPORTED_RUBRICS = ['calculator', 'manifesto', 'vs_marketplace'];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $fontPath,
    ) {
    }

    public function supports(string $rubric): bool
    {
        return in_array($rubric, self::SUPPORTED_RUBRICS, true);
    }

    public function render(SocialPost $post): ?string
    {
        if (!is_file($this->fontPath)) {
            return null;
        }

        $headline = $this->headline($post);
        if ($headline === '') {
            return null;
        }

        $im = imagecreatetruecolor(self::SIZE, self::SIZE);
        $bg = imagecolorallocate($im, 17, 24, 39); // tailwind gray-900
        $fg = imagecolorallocate($im, 255, 255, 255);
        $muted = imagecolorallocate($im, 156, 163, 175); // tailwind gray-400
        imagefilledrectangle($im, 0, 0, self::SIZE - 1, self::SIZE - 1, $bg);

        $maxWidth = self::SIZE - 2 * self::PADDING;
        $lines = $this->wrap($headline, $maxWidth);

        $blockHeight = count($lines) * self::LINE_HEIGHT;
        $y = (int) ((self::SIZE - $blockHeight) / 2) + self::FONT_SIZE;

        foreach ($lines as $line) {
            $bbox = imagettfbbox(self::FONT_SIZE, 0, $this->fontPath, $line);
            $x = (int) ((self::SIZE - ($bbox[2] - $bbox[0])) / 2);
            imagettftext($im, self::FONT_SIZE, 0, $x, $y, $fg, $this->fontPath, $line);
            $y += self::LINE_HEIGHT;
        }

        $footer = 'WEARBASE · #ПрямойБренд';
        $footerSize = 26;
        $fbbox = imagettfbbox($footerSize, 0, $this->fontPath, $footer);
        $fx = (int) ((self::SIZE - ($fbbox[2] - $fbbox[0])) / 2);
        imagettftext($im, $footerSize, 0, $fx, self::SIZE - 60, $muted, $this->fontPath, $footer);

        return $this->save($post, $im);
    }

    /** Заголовок карточки = первая строка подписи (см. шаблон подписи CaptionGenerator/§6 канона). */
    private function headline(SocialPost $post): string
    {
        $firstLine = trim(explode("\n", (string) $post->getCaption(), 2)[0]);
        if ($firstLine === '') {
            return '';
        }

        return mb_strlen($firstLine) > self::MAX_HEADLINE_CHARS
            ? mb_substr($firstLine, 0, self::MAX_HEADLINE_CHARS - 1) . '…'
            : $firstLine;
    }

    /** @return string[] */
    private function wrap(string $text, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            $bbox = imagettfbbox(self::FONT_SIZE, 0, $this->fontPath, $test);
            if (($bbox[2] - $bbox[0]) > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function save(SocialPost $post, \GdImage $im): ?string
    {
        $dir = $this->projectDir . '/public_html/images/social';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $name = sprintf('card-%d.png', (int) $post->getId());
        $ok = imagepng($im, $dir . '/' . $name);
        imagedestroy($im);

        return $ok ? '/images/social/' . $name : null;
    }
}
