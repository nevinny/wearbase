<?php

namespace App\Service;

/**
 * SearXNG недоступен или все его движки suspended (CAPTCHA/rate-limit).
 * Сигнал «поиск лежит» — discovery НЕ должен помечать бренд discovered
 * (иначе бренд сгорает с пустыми тирами и больше не переобходится).
 */
class SearxUnavailableException extends \RuntimeException
{
}
