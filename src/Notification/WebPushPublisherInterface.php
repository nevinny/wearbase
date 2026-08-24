<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;

interface WebPushPublisherInterface
{
    /** @param array<string, mixed> $payload */
    public function send(User $recipient, array $payload): bool;
}
