<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPBatchStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_filter;

class BatchMessageBus implements MessageBusInterface, BatchMessageBusInterface
{
    /** @var array<BatchTransportInterface>|null */
    private array|null $batchTransports = null;

    /**
     * @param array<TransportInterface> $transports
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private MessageBusInterface $wrappedBus,
        private array $transports,
    ) {
    }

    /** @inheritDoc */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->wrappedBus->dispatch($message, $stamps);
    }

    /** @inheritDoc */
    #[Override]
    public function dispatchBatches(iterable $messages, int $batchSize = 100): void
    {
        $batch = [];
        $count = 0;

        foreach ($messages as $message) {
            $batch[] = $message;
            $count++;

            if ($count !== $batchSize) {
                continue;
            }

            $this->dispatchBatch($batch, $batchSize);

            $batch = [];
            $count = 0;
        }

        if ($count === 0) {
            return;
        }

        $this->dispatchBatch($batch, $batchSize);
        $this->flush();
    }

    /** @throws ExceptionInterface */
    #[Override]
    public function dispatchInBatch(object $message, int $batchSize): void
    {
        $envelope = Envelope::wrap($message)
            ->with(new AMQPBatchStamp($batchSize));

        $this->dispatch($envelope);
    }

    #[Override]
    public function flush(): void
    {
        foreach ($this->getBatchTransports() as $batchTransport) {
            $batchTransport->flush();
        }
    }

    /** @param array<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->wrappedBus->{$method}(...$arguments);
    }

    /** @param array<object|Envelope> $batch */
    private function dispatchBatch(array $batch, int $batchSize): void
    {
        foreach ($batch as $message) {
            $this->dispatchInBatch($message, $batchSize);
        }
    }

    /** @return array<BatchTransportInterface> */
    private function getBatchTransports(): array
    {
        if ($this->batchTransports === null) {
            $this->batchTransports = array_filter($this->transports, static function (TransportInterface $transport): bool {
                return $transport instanceof BatchTransportInterface;
            });
        }

        return $this->batchTransports;
    }
}
