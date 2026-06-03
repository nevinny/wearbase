<?php

namespace App\Service\Keyword;

/**
 * Брошено при исчерпании часовой квоты Wordstat (100 запросов/час).
 * Команда ловит его и останавливает прогон (resumable — добор при перезапуске),
 * вместо того чтобы вхолостую помечать остальные бренды пустыми.
 */
class WordstatQuotaException extends \RuntimeException
{
}
