<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Throwable;

class AMQPTransport implements QueueReceiverInterface, MessageCountAwareInterface, SetupableTransportInterface, BatchTransportInterface
{
    public function __construct(
        private Connection $connection,
        private AMQPReceiver|null $receiver = null,
        private AMQPSender|null $sender = null,
        private SerializerInterface|null $serializer = null,
    ) {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /** @inheritDoc */
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

    private function getReceiver(): AMQPReceiver
    {
        return $this->receiver ??= new AMQPReceiver(
            $this->connection,
            $this->getSerializer(),
        );
    }

    private function getSender(): AMQPSender
    {
        return $this->sender ??= new AMQPSender(
            $this->connection,
            $this->getSerializer(),
        );
    }
}
