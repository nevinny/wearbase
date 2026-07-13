<?php

declare(strict_types=1);

namespace App\Service\Social\Source;

use App\Entity\SocialPost;

/**
 * Источник тела подписи. Каждая рубрика (SocialRubrics::CATALOG) ссылается на источник
 * по source-ключу (SocialRubrics::SOURCE_*), резолвится в CaptionGenerator по key()
 * (паттерн PaymentGatewayRegistry/SocialPublisherRegistry). Хэштеги и CTA/UTM остаются
 * за CaptionGenerator — источник отвечает только за текст подписи.
 */
interface CaptionSourceInterface
{
    /** Ключ источника из SocialRubrics::SOURCE_* — по нему резолвится в CaptionGenerator. */
    public function key(): string;

    /** Собрать тело подписи для этого поста (без хэштегов и CTA). */
    public function body(SocialPost $post): string;
}
