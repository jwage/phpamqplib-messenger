<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Override;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class WrappedBus implements MessageBusInterface
{
    public array $dispatched = [];

    /** @inheritDoc */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = (new Envelope($message))->with(...$stamps);
        $this->dispatched[] = $envelope;
        return $envelope;

    }

    public function getLastDispatched(): Envelope
    {
        if (empty($this->dispatched)) {
            throw new RuntimeException('No envelopes in dispatched stack');
        }

        return array_pop($this->dispatched);
    }

    public function someMethod(string $arg1, string $arg2): string
    {
        return 'result';
    }
}
