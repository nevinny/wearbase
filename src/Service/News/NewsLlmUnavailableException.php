<?php

declare(strict_types=1);

namespace App\Service\News;

/** LLM недоступна (таймаут/сеть/не-2xx): item остаётся в fetched, конвейер не падает. */
final class NewsLlmUnavailableException extends \RuntimeException
{
}
