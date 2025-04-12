<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Override;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

use function assert;
use function is_string;

class AMQPSender implements SenderInterface
{
    public function __construct(
        private RetryFactory $retryFactory,
        private Connection $connection,
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * @throws TransportException
     * @throws Throwable
     */
    #[Override]
    public function send(Envelope $envelope): Envelope
    {
        $encodedMessage = $this->serializer->encode($envelope);

        $batchStamp = $envelope->last(AMQPBatchStamp::class);
        $batchSize  = $batchStamp ? $batchStamp->getBatchSize() : 1;

        $delayStamp = $envelope->last(DelayStamp::class);
        $delay      = $delayStamp ? $delayStamp->getDelay() : 0;

        $amqpStamp = $envelope->last(AMQPStamp::class);
        if ($amqpStamp instanceof AMQPStamp && isset($amqpStamp->getAttributes()['message_id'])) {
            $envelope = $envelope->with(new TransportMessageIdStamp($amqpStamp->getAttributes()['message_id']));
        }

        $amqpReceivedStamp = $envelope->last(AMQPReceivedStamp::class);
        if ($amqpReceivedStamp instanceof AMQPReceivedStamp) {
            $amqpStamp = AMQPStamp::createFromAMQPEnvelope(
                $amqpReceivedStamp->getAMQPEnvelope(),
                $amqpStamp,
                $envelope->last(RedeliveryStamp::class) ? $amqpReceivedStamp->getQueueName() : null,
            );
        }

        try {
            $this->retryFactory->retry()
                ->run(function (Retry $_retry, bool $isRetry) use ($encodedMessage, $batchSize, $delay, $amqpStamp): void {
                    if ($isRetry) {
                        $this->connection->reconnect();
                    }

                    $body = $encodedMessage['body'];
                    assert(is_string($body));

                    $this->connection->publish(
                        body: $body,
                        delayInMs: $delay,
                        batchSize: $batchSize,
                        amqpStamp: $amqpStamp,
                    );
                });
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return $envelope;
    }
}
