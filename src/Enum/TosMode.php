<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Правовой режим источника (см. _docs/news-sources-tos.md):
 * facts_only — берём только факты, текст пишем сами («заметка на основе фактов»);
 * forbidden  — правообладатель прямо запретил перепечатку/переработку/автосбор
 *              (Buro 24/7, РБК Стиль) — жёсткий skip в конвейере.
 */
enum TosMode: string
{
    case FactsOnly = 'facts_only';
    case Forbidden = 'forbidden';
}
