<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferrableStamp;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferredStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class Batch implements MessageBusInterface
{
    /** @var array<BatchTransportInterface> */
    private array $transportsToFlush = [];

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
            ->with(new DeferrableStamp($this->batchSize));

        $envelope = $this->wrappedBus->dispatch($envelope);

        if (($stamp = $envelope->last(DeferredStamp::class)) !== null) {
            $this->transportsToFlush[] = $stamp->getTransport();
        }

        return $envelope;
    }

    public function flush(): void
    {
        foreach ($this->transportsToFlush as $transport) {
            $transport->flush();
        }
    }

    /** @param array<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->wrappedBus->{$method}(...$arguments);
    }
}
