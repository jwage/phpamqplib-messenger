<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

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
        /** @var list<StampInterface> $typedStamps */
        $typedStamps = $stamps;

        return $this->transport->send(Envelope::wrap($message, $typedStamps));
    }
}
