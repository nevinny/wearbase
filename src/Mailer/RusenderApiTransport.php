<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Отправка через Rusender HTTP API (транзакционные письма).
 * Зачем: SMTP-доступ у Rusender платный/требует активации, а API-ключ
 * («Транзакционные отправки → Ключ») работает на бесплатном тарифе.
 *
 * DSN: rusender+api://API_KEY@default[?key_id=4487]
 *  - key_id задан → POST /api/v1/external-mails/send/{key_id} (новый формат, Bearer)
 *  - без key_id   → POST /api/v1/external-mails/send (старый формат, X-Api-Key)
 */
final class RusenderApiTransport extends AbstractApiTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $keyId = null,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'rusender+api://' . $this->getEndpoint();
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $from = $email->getFrom()[0] ?? null;
        $path = $this->keyId !== null && $this->keyId !== ''
            ? "/api/v1/external-mails/send/{$this->keyId}"
            : '/api/v1/external-mails/send';

        $headers = str_starts_with($this->apiKey, 'rs_')
            ? ['Authorization' => 'Bearer ' . $this->apiKey]
            : ['X-Api-Key' => $this->apiKey];

        $lastResponse = null;
        // API принимает одного получателя на запрос
        foreach ($email->getTo() as $to) {
            $payload = [
                'mail' => [
                    'to'      => array_filter(['email' => $to->getAddress(), 'name' => $to->getName() ?: null]),
                    'from'    => array_filter(['email' => $from?->getAddress(), 'name' => $from?->getName() ?: null]),
                    'subject' => mb_substr((string) $email->getSubject(), 0, 255),
                ],
            ];
            if ($email->getHtmlBody() !== null) {
                $payload['mail']['html'] = (string) $email->getHtmlBody();
            }
            if ($email->getTextBody() !== null) {
                $payload['mail']['text'] = (string) $email->getTextBody();
            }

            $response = $this->client->request('POST', 'https://' . $this->getEndpoint() . $path, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300) {
                $detail = '';
                try {
                    $detail = substr($response->getContent(false), 0, 300);
                } catch (\Throwable) {
                }
                throw new HttpTransportException(
                    sprintf('Rusender API error %d for "%s": %s', $statusCode, $to->getAddress(), $detail),
                    $response,
                );
            }
            $lastResponse = $response;
        }

        if ($lastResponse === null) {
            throw new HttpTransportException('Rusender API: письмо без получателей', $this->client->request('GET', 'https://' . $this->getEndpoint()));
        }

        return $lastResponse;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: 'api.rusender.ru') . ($this->port ? ':' . $this->port : '');
    }
}
