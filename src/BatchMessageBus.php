<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class BatchMessageBus implements BatchMessageBusInterface
{
    public function __construct(
        private MessageBusInterface $wrappedBus,
    ) {
    }

    #[Override]
    public function getBatch(int $batchSize): Batch
    {
        return new Batch($this->wrappedBus, $batchSize);
    }

    /** @inheritDoc */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->wrappedBus->dispatch($message, $stamps);
    }

    /** @param array<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->wrappedBus->{$method}(...$arguments);
    }
}
