<?php

namespace App\Service\Keyword;

/**
 * Брошено при ошибке авторизации Wordstat (невалидный/удалённый API-ключ:
 * HTTP 401/403 или gRPC code 16 UNAUTHENTICATED «Unknown api key»).
 * Команда ловит его и сразу останавливает прогон — продолжать против дохлого
 * ключа бессмысленно (иначе все бренды молча пометятся пустыми).
 */
class WordstatAuthException extends \RuntimeException
{
}
