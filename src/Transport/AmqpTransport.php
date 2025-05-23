<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferrableStamp;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferredStamp;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Throwable;

class AmqpTransport implements QueueReceiverInterface, MessageCountAwareInterface, SetupableTransportInterface, BatchTransportInterface
{
    public function __construct(
        private Connection $connection,
        private AmqpReceiver|null $receiver = null,
        private AmqpSender|null $sender = null,
        private SerializerInterface|null $serializer = null,
    ) {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @return iterable<Envelope>
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     */
    #[Override]
    public function get(): iterable
    {
        return $this->getReceiver()->get();
    }

    /**
     * @param array<string> $queueNames
     *
     * @return iterable<Envelope>
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     */
    #[Override]
    public function getFromQueues(array $queueNames): iterable
    {
        return $this->getReceiver()->getFromQueues($queueNames);
    }

    /** @throws Throwable */
    #[Override]
    public function ack(Envelope $envelope): void
    {
        $this->getReceiver()->ack($envelope);
    }

    /** @throws Throwable */
    #[Override]
    public function reject(Envelope $envelope): void
    {
        $this->getReceiver()->reject($envelope);
    }

    /**
     * @throws TransportException
     * @throws Throwable
     */
    #[Override]
    public function send(Envelope $envelope): Envelope
    {
        if ($envelope->last(DeferrableStamp::class) !== null) {
            $envelope = $envelope->with(new DeferredStamp($this));
        }

        return $this->getSender()->send($envelope);
    }

    /**
     * @throws TransportException
     * @throws Throwable
     */
    #[Override]
    public function flush(): void
    {
        $this->getSender()->flush();
    }

    /** @throws TransportException */
    #[Override]
    public function getMessageCount(): int
    {
        return $this->getReceiver()->getMessageCount();
    }

    /**
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    #[Override]
    public function setup(): void
    {
        $this->connection->setup();
    }

    private function getSerializer(): SerializerInterface
    {
        return $this->serializer ??= new PhpSerializer();
    }

    private function getReceiver(): AmqpReceiver
    {
        return $this->receiver ??= new AmqpReceiver(
            $this->connection,
            $this->getSerializer(),
        );
    }

    private function getSender(): AmqpSender
    {
        return $this->sender ??= new AmqpSender(
            $this->connection,
            $this->getSerializer(),
        );
    }
}
