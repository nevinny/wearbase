<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class RusenderTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!\in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'rusender', $this->getSupportedSchemes());
        }

        // client/dispatcher/logger — из AbstractTransportFactory; dispatcher критичен:
        // без него не рендерится тело TemplatedEmail (см. комментарий в транспорте).
        $transport = new RusenderApiTransport(
            $this->getUser($dsn),
            $dsn->getOption('key_id'),
            $this->client,
            $this->dispatcher,
            $this->logger,
        );

        if ('default' !== $dsn->getHost()) {
            $transport->setHost($dsn->getHost());
        }
        if (null !== $dsn->getPort()) {
            $transport->setPort($dsn->getPort());
        }

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return ['rusender', 'rusender+api'];
    }
}
