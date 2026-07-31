<?php

declare(strict_types=1);

namespace App\Service\Social;

/**
 * Сценарий надписей поста-галереи v3: не «хук → реплика → CTA» (SlideHookComposer /
 * первая версия SlideScript просили комментарий-цифру — comment bait, который Meta
 * демоутит и который ничего не доказывает про бренд), а лестница внимания на честных
 * фактах бренда.
 *
 * Драматургия по кадрам (раскладывает GallerySlideRenderer, слова даёт SlideScriptComposer):
 * 1. hookA — одна строка поверх первого фото;
 * 2. hookA + hookB — та же плашка, верхне-выровненная (hookA не сдвигается);
 * 3. кадры-биты (4, 6, 8…) — по одному факту, до {@see self::MAX_BITS};
 * N (последний). развязка — инверсная плашка: имя бренда, «город · категории», просьба
 *    сохранить. Одна и та же для веток A/B — эксперимент сравнивает только порядок логотипа,
 *    не текст (variant на вход compose() намеренно не подаётся).
 *
 * scriptKey фиксирует РЕАЛИЗОВАННУЮ ступень лестницы хуков + источник битов + версию
 * развязки (напр. 'h2.city|b.rag2|c.save') — по нему считается closed-loop (app:social:evaluate)
 * и решается held/scheduled (SocialGenerateCommand: биты из LLM → held на ручной просмотр).
 */
final class SlideScript
{
    /** hookA/hookB и каждый бит — одна строка не длиннее стольки знаков. */
    public const MAX_LINE_CHARS = 22;

    /** «{Город} · {категории}» на кадре-развязке — короче, это уже мелкий кегль под ней. */
    public const FINALE_META_MAX_CHARS = 30;

    /** Максимум фактов на кадрах-битах — больше на коротком клипе превращается в стену текста. */
    public const MAX_BITS = 3;

    /** Первый кадр-бит — после hookA (1) и hookA+hookB (2) и одного чистого кадра (3). */
    private const FIRST_BIT_FRAME = 4;

    /** Биты стоят через кадр — иначе на глаз клип выглядит как бегущая строка, а не слайдшоу. */
    private const BIT_FRAME_STEP = 2;

    /**
     * @param list<string> $bits 0..MAX_BITS фактов, каждый ≤MAX_LINE_CHARS
     */
    public function __construct(
        public readonly string $hookA,
        public readonly string $hookB,
        public readonly array $bits,
        public readonly string $finaleTitle,
        public readonly string $finaleMeta,
        public readonly string $finaleAsk,
        public readonly string $scriptKey,
    ) {
    }

    /**
     * Сколько кадров-битов помещается в ролик из $totalSlides кадров: бит-кадр не может
     * оказаться на месте развязки (последний кадр), поэтому годятся только 4,6,8… ≤ N−1,
     * и не больше MAX_BITS в любом случае (иначе клип читается как список, а не история).
     */
    public static function maxBits(int $totalSlides): int
    {
        if ($totalSlides < self::FIRST_BIT_FRAME + 1) {
            return 0;
        }

        $count = intdiv($totalSlides - 1 - self::FIRST_BIT_FRAME, self::BIT_FRAME_STEP) + 1;

        return min(self::MAX_BITS, $count);
    }

    /** @return list<int> 1-based номера кадров для $count битов, начиная с 4-го, шаг 2. */
    public static function bitFrameIndices(int $count): array
    {
        $indices = [];
        for ($i = 0; $i < $count; $i++) {
            $indices[] = self::FIRST_BIT_FRAME + $i * self::BIT_FRAME_STEP;
        }

        return $indices;
    }

    /**
     * @return array{hookA:string,hookB:string,bits:list<string>,finaleTitle:string,finaleMeta:string,finaleAsk:string,scriptKey:string}
     */
    public function toArray(): array
    {
        return [
            'hookA' => $this->hookA,
            'hookB' => $this->hookB,
            'bits' => $this->bits,
            'finaleTitle' => $this->finaleTitle,
            'finaleMeta' => $this->finaleMeta,
            'finaleAsk' => $this->finaleAsk,
            'scriptKey' => $this->scriptKey,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['hookA'] ?? ''),
            (string) ($data['hookB'] ?? ''),
            array_values(array_map('strval', (array) ($data['bits'] ?? []))),
            (string) ($data['finaleTitle'] ?? ''),
            (string) ($data['finaleMeta'] ?? ''),
            (string) ($data['finaleAsk'] ?? ''),
            (string) ($data['scriptKey'] ?? ''),
        );
    }
}
