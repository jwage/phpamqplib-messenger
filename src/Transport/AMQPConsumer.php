<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

use function array_shift;

class AMQPConsumer
{
    /** @var array<AMQPEnvelope> */
    private array $buffer = [];

    private bool $isConsuming = false;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws InvalidArgumentException
     */
    public function get(string $queueName, int $timeout = 1): AMQPEnvelope|null
    {
        $channel = $this->connection->channel();

        if ($this->isConsuming === false) {
            $channel->basic_qos(
                prefetch_size: 0,
                prefetch_count: 1,
                a_global: false,
            );

            $channel->basic_consume(
                queue: $queueName,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: $this->callback(...),
            );

            $this->isConsuming = true;
        }

        if ($this->buffer === []) {
            try {
                $channel->wait(
                    allowed_methods: null,
                    non_blocking: false,
                    timeout: $timeout,
                );
            // When we get the timeout from wait(), do nothing
            } catch (AMQPTimeoutException) {
            }
        }

        return array_shift($this->buffer);
    }

    public function callback(AMQPMessage $amqpMessage): void
    {
        $this->buffer[] = new AMQPEnvelope($amqpMessage);
    }
}
