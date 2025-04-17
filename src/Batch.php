<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\Deferrable;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\Flush;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class Batch implements MessageBusInterface
{
    public function __construct(
        private MessageBusInterface $wrappedBus,
        private int $batchSize,
    ) {
    }

    public function __destruct()
    {
        $this->flush();
    }

    /** @inheritDoc */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message)
            ->with(new Deferrable($this->batchSize));

        return $this->wrappedBus->dispatch($envelope);
    }

    public function flush(): void
    {
        $this->wrappedBus->dispatch(new Flush());
    }

    /** @param array<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->wrappedBus->{$method}(...$arguments);
    }
}
