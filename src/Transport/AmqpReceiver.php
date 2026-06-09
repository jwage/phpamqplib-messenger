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

use function func_get_arg;
use function func_num_args;
use function max;

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
    public function get(/* int $fetchSize = 1 */): iterable
    {
        if (func_num_args() > 0) {
            yield from $this->getFromQueues($this->connection->getQueueNames(), max(1, (int) func_get_arg(0)));

            return;
        }

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
    public function getFromQueues(array $queueNames/* , int $fetchSize = 1 */): iterable
    {
        $remaining = func_num_args() > 1 ? max(1, (int) func_get_arg(1)) : null;

        if ($remaining !== null) {
            foreach ($queueNames as $queueName) {
                foreach ($this->getEnvelopes($queueName) as $envelope) {
                    yield $envelope;

                    if (--$remaining <= 0) {
                        return;
                    }
                }
            }

            return;
        }

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
        $amqpEnvelopes = $this->connection->consume($queueName);

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
