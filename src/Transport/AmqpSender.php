<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferrableStamp;
use LogicException;
use Override;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp as SymfonyAmqpStamp;
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
use function sprintf;

class AmqpSender implements SenderInterface, BatchSenderInterface
{
    public function __construct(
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
        /** @psalm-suppress UndefinedClass */
        if ($envelope->last(SymfonyAmqpStamp::class) !== null) {
            throw new LogicException(sprintf(
                'Wrong AmqpStamp class used. Switch your code from using "%s" to "%s".',
                SymfonyAmqpStamp::class,
                AmqpStamp::class,
            ));
        }

        $encodedMessage = $this->serializer->encode($envelope);

        $deferrable = $envelope->last(DeferrableStamp::class);
        $batchSize  = $deferrable ? $deferrable->getBatchSize() : 1;

        $delayStamp = $envelope->last(DelayStamp::class);
        $delay      = $delayStamp ? $delayStamp->getDelay() : 0;

        $amqpStamp = $envelope->last(AmqpStamp::class);

        if (isset($encodedMessage['headers']['Content-Type'])) {
            $contentType = $encodedMessage['headers']['Content-Type'];
            assert(is_string($contentType));
            unset($encodedMessage['headers']['Content-Type']);

            if (! $amqpStamp || ! isset($amqpStamp->getAttributes()['content_type'])) {
                $amqpStamp = AmqpStamp::createWithAttributes(['content_type' => $contentType], $amqpStamp);
            }
        }

        if ($amqpStamp instanceof AmqpStamp && isset($amqpStamp->getAttributes()['message_id'])) {
            $envelope = $envelope->with(new TransportMessageIdStamp($amqpStamp->getAttributes()['message_id']));
        }

        $amqpReceivedStamp = $envelope->last(AmqpReceivedStamp::class);

        if ($amqpReceivedStamp instanceof AmqpReceivedStamp) {
            $amqpStamp = AmqpStamp::createFromAMQPEnvelope(
                $amqpReceivedStamp->getAMQPEnvelope(),
                $amqpStamp,
                $envelope->last(RedeliveryStamp::class) ? $amqpReceivedStamp->getQueueName() : null,
            );
        }

        $body = $encodedMessage['body'];
        assert(is_string($body));

        /** @var array<string, mixed> $headers */
        $headers = $encodedMessage['headers'] ?? [];

        $this->connection->publish(
            body: $body,
            headers: $headers,
            delayInMs: $delay,
            batchSize: $batchSize,
            amqpStamp: $amqpStamp,
        );

        return $envelope;
    }

    /**
     * @throws TransportException
     * @throws Throwable
     */
    #[Override]
    public function flush(): void
    {
        $this->connection->flush();
    }
}
