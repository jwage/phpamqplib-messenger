<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class TransportDispatchBus implements MessageBusInterface
{
    public function __construct(
        private AmqpTransport $transport,
    ) {
    }

    /** @inheritDoc */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->transport->send(Envelope::wrap($message, $stamps));
    }
}
