<?php

declare(strict_types=1);

namespace App\Service\Social;

/** Ошибка Instagram API; permanent=true — повторять бессмысленно (окно истекло, комментарий удалён и т.п.). */
final class InstagramApiException extends \RuntimeException
{
    public function __construct(string $message, private readonly bool $permanent)
    {
        parent::__construct($message);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
