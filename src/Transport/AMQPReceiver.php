<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
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

use function assert;

class AMQPReceiver implements QueueReceiverInterface, MessageCountAwareInterface
{
    public function __construct(
        private RetryFactory $retryFactory,
        private Connection $connection,
        private SerializerInterface $serializer,
    ) {
    }

    /** @return iterable<Envelope> */
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
            yield from $this->getEnvelope($queueName);
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
        $amqpEnvelope = $this->findAMQPReceivedStamp($envelope)->getAMQPEnvelope();

        try {
            $this->retryFactory->retry()
                ->catch(AMQPExceptionInterface::class)
                ->run(static function () use ($amqpEnvelope): void {
                    $amqpEnvelope->ack();
                });
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws TransportException
     * @throws LogicException
     * @throws Throwable
     */
    #[Override]
    public function reject(Envelope $envelope): void
    {
        $amqpEnvelope = $this->findAMQPReceivedStamp($envelope)->getAMQPEnvelope();

        try {
            $this->retryFactory->retry()
                ->catch(AMQPExceptionInterface::class)
                ->run(static function () use ($amqpEnvelope): void {
                    $amqpEnvelope->nack();
                });
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
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
    private function getEnvelope(string $queueName): iterable
    {
        try {
            $amqpEnvelope = $this->retryFactory->retry()
                ->run(function (Retry $_retry, bool $isRetry) use ($queueName): AMQPEnvelope|null {
                    if ($isRetry) {
                        $this->connection->reconnect();
                    }

                    return $this->connection->get($queueName);
                });
            assert($amqpEnvelope instanceof AMQPEnvelope || $amqpEnvelope === null);
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        if ($amqpEnvelope === null) {
            return;
        }

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

        yield $envelope->with(new AMQPReceivedStamp($amqpEnvelope, $queueName));
    }

    /** @throws LogicException */
    private function findAMQPReceivedStamp(Envelope $envelope): AMQPReceivedStamp
    {
        $amqpReceivedStamp = $envelope->last(AMQPReceivedStamp::class);

        if ($amqpReceivedStamp === null) {
            throw new LogicException('No "AMQPReceivedStamp" stamp found on the Envelope.');
        }

        return $amqpReceivedStamp;
    }
}
