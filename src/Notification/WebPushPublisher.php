<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use App\Repository\WebPushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

final readonly class WebPushPublisher implements WebPushPublisherInterface
{
    public function __construct(
        private WebPushSubscriptionRepository $subscriptions,
        private LoggerInterface $logger,
        private string $publicKey,
        private string $privateKey,
        private string $subject,
    ) {}

    public function send(User $recipient, array $payload): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('Web Push delivery is disabled because VAPID is not configured.');
            return false;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ]]);
            $json = json_encode([
                'title' => is_string($payload['title'] ?? null) ? $payload['title'] : 'WEARBASE',
                'body' => is_string($payload['body'] ?? null) ? $payload['body'] : null,
                'url' => is_string($payload['url'] ?? null) ? $payload['url'] : '/account/notifications',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $success = true;
            foreach ($this->subscriptions->findActiveForUser($recipient) as $stored) {
                $subscription = Subscription::create([
                    'endpoint' => $stored->getEndpoint(),
                    'publicKey' => $stored->getPublicKey(),
                    'authToken' => $stored->getAuthToken(),
                    'contentEncoding' => $stored->getContentEncoding(),
                ]);
                $report = $webPush->sendOneNotification($subscription, $json);
                if ($report->isSubscriptionExpired()) {
                    $stored->revoke();
                } elseif (!$report->isSuccess()) {
                    $this->logger->warning('Web Push delivery failed.', ['status_code' => $report->getResponse()?->getStatusCode()]);
                    $success = false;
                }
            }

            return $success;
        } catch (\Throwable) {
            // Endpoint and browser keys are bearer-like secrets: never include them in logs.
            $this->logger->warning('Web Push delivery failed before a provider response.');
            return false;
        }
    }

    private function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '' && str_starts_with($this->subject, 'mailto:');
    }
}
