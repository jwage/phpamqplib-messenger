<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Override;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Throwable;

class AMQPTransport implements ReceiverInterface, TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    public function __construct(
        private RetryFactory $retryFactory,
        private Connection $connection,
        private AMQPReceiver|null $receiver = null,
        private AMQPSender|null $sender = null,
        private SerializerInterface|null $serializer = null,
    ) {
    }

    /** @inheritDoc */
    #[Override]
    public function get(): iterable
    {
        return $this->getReceiver()->get();
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

    /** @throws TransportException */
    #[Override]
    public function getMessageCount(): int
    {
        return $this->getReceiver()->getMessageCount();
    }

    /**
     * @throws AMQPExceptionInterface
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
            $this->retryFactory,
            $this->connection,
            $this->getSerializer(),
        );
    }

    private function getSender(): AMQPSender
    {
        return $this->sender ??= new AMQPSender(
            $this->retryFactory,
            $this->connection,
            $this->getSerializer(),
        );
    }
}
