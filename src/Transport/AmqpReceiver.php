<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use LogicException;
use Override;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

class AmqpReceiver implements QueueReceiverInterface, MessageCountAwareInterface
{
    public function __construct(
        private Connection $connection,
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * @return iterable<Envelope>
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     */
    #[Override]
    public function get(): iterable
    {
        yield from $this->getFromQueues($this->connection->getQueueNames());
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
        foreach ($queueNames as $queueName) {
            yield from $this->getEnvelopes($queueName);
        }
    }

    /**
     * @throws TransportException
     * @throws LogicException
     * @throws Throwable
     */
    #[Override]
    public function ack(Envelope $envelope): void
    {
        $amqpEnvelope = $this->findAMQPReceivedStamp($envelope)->getAmqpEnvelope();
        $amqpEnvelope->ack();
    }

    /**
     * @throws TransportException
     * @throws LogicException
     * @throws Throwable
     */
    #[Override]
    public function reject(Envelope $envelope): void
    {
        $amqpEnvelope = $this->findAMQPReceivedStamp($envelope)->getAmqpEnvelope();
        $amqpEnvelope->nack();
    }

    /** @throws TransportException */
    #[Override]
    public function getMessageCount(): int
    {
        try {
            return $this->connection->countMessagesInQueues();
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return iterable<Envelope>
     *
     * @throws MessageDecodingFailedException
     * @throws TransportException
     * @throws Throwable
     */
    private function getEnvelopes(string $queueName): iterable
    {
        $amqpEnvelopes = $this->connection->get($queueName);

        foreach ($amqpEnvelopes as $amqpEnvelope) {
            $body = $amqpEnvelope->getBody();

            $headers = $amqpEnvelope->getHeaders();

            try {
                $envelope = $this->serializer->decode([
                    'body' => $body,
                    'headers' => $headers,
                ]);
            } catch (MessageDecodingFailedException $e) {
                $amqpEnvelope->nack();

                throw $e;
            }

            if (($messageId = $amqpEnvelope->getMessageId()) !== null) {
                $envelope = $envelope
                    ->withoutAll(TransportMessageIdStamp::class)
                    ->with(new TransportMessageIdStamp($messageId));
            }

            yield $envelope->with(new AmqpReceivedStamp($amqpEnvelope, $queueName));
        }
    }

    /** @throws LogicException */
    private function findAMQPReceivedStamp(Envelope $envelope): AmqpReceivedStamp
    {
        $amqpReceivedStamp = $envelope->last(AmqpReceivedStamp::class);

        if ($amqpReceivedStamp === null) {
            throw new LogicException('No "AMQPReceivedStamp" stamp found on the Envelope.');
        }

        return $amqpReceivedStamp;
    }
}
