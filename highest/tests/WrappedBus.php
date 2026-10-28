<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class WrappedBus implements MessageBusInterface
{
    public function __construct(
        private MessageBusInterface $wrappedBus,
    ) {
    }

    /** @inheritDoc */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->wrappedBus->dispatch($message, $stamps);
    }

    public function someMethod(string $arg1, string $arg2): string
    {
        return 'result';
    }
}
