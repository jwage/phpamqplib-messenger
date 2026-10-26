<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use LogicException;
use OutOfBoundsException;
use PhpAmqpLib\Exception\AMQPEmptyDeliveryTagException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

use function assert;
use function is_numeric;
use function is_string;
use function sprintf;

class AmqpEnvelope
{
    /** True for a copy that went through serialization and so has no channel to ack or nack on. */
    private bool $detached = false;

    public function __construct(
        private AMQPMessage $amqpMessage,
        private bool $noAck = false,
    ) {
    }

    /**
     * Copies the message without its channel: the copy can be read in another
     * process, but only the instance that received the message can ack or nack it.
     *
     * @return array{body: string, properties: array<mixed>, deliveryTag: int|null, redelivered: bool|null, exchange: string|null, routingKey: string|null, consumerTag: string|null, noAck: bool}
     */
    public function __serialize(): array
    {
        $message = $this->amqpMessage;

        try {
            $deliveryTag = $message->getDeliveryTag();
        } catch (AMQPEmptyDeliveryTagException) {
            $deliveryTag = null;
        }

        return [
            'body' => $message->getBody(),
            'properties' => $message->get_properties(),
            'deliveryTag' => $deliveryTag,
            'redelivered' => $message->isRedelivered(),
            'exchange' => $message->getExchange(),
            'routingKey' => $message->getRoutingKey(),
            'consumerTag' => $message->getConsumerTag(),
            'noAck' => $this->noAck,
        ];
    }

    /** @param array{body?: string, properties?: array<mixed>, deliveryTag?: int|null, redelivered?: bool|null, exchange?: string|null, routingKey?: string|null, consumerTag?: string|null, noAck?: bool} $data */
    public function __unserialize(array $data): void
    {
        $message = new AMQPMessage($data['body'] ?? '', $data['properties'] ?? []);

        // A message that was never delivered has no delivery info to restore.
        $deliveryTag = $data['deliveryTag'] ?? null;
        if ($deliveryTag !== null) {
            $message->setDeliveryInfo(
                $deliveryTag,
                $data['redelivered'] ?? false,
                $data['exchange'] ?? '',
                $data['routingKey'] ?? '',
            );
        }

        $consumerTag = $data['consumerTag'] ?? null;
        if ($consumerTag !== null) {
            $message->setConsumerTag($consumerTag);
        }

        $this->amqpMessage = $message;
        $this->noAck       = $data['noAck'] ?? false;
        $this->detached    = true;
    }

    public function getAMQPMessage(): AMQPMessage
    {
        return $this->amqpMessage;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->amqpMessage->get_properties();

        return $attributes;
    }

    /** @throws LogicException When the envelope is an unserialized copy. */
    public function ack(): void
    {
        if ($this->noAck) {
            return;
        }

        $this->assertNotDetached('ack');
        $this->amqpMessage->ack();
    }

    /** @throws LogicException When the envelope is an unserialized copy. */
    public function nack(): void
    {
        if ($this->noAck) {
            return;
        }

        $this->assertNotDetached('nack');
        $this->amqpMessage->nack();
    }

    /** @throws LogicException */
    private function assertNotDetached(string $operation): void
    {
        if ($this->detached) {
            throw new LogicException(sprintf('Cannot %s an AmqpEnvelope that was unserialized: only the process holding the original channel can acknowledge the message.', $operation));
        }
    }

    public function getBody(): string
    {
        return $this->amqpMessage->getBody();
    }

    public function getRoutingKey(): string|null
    {
        return $this->amqpMessage->getRoutingKey();
    }

    public function getContentType(): string|null
    {
        return $this->getString('content_type');
    }

    public function getContentEncoding(): string|null
    {
        return $this->getString('content_encoding');
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        $applicationHeaders = $this->get('application_headers');
        assert($applicationHeaders instanceof AMQPTable || $applicationHeaders === null);

        if ($applicationHeaders instanceof AMQPTable) {
            /** @var array<string, string> $headers */
            $headers = $applicationHeaders->getNativeData();

            return $headers;
        }

        return [];
    }

    public function getHeader(string $key): mixed
    {
        return $this->getHeaders()[$key] ?? null;
    }

    public function getDeliveryMode(): int|null
    {
        return $this->getInt('delivery_mode');
    }

    public function getPriority(): int|null
    {
        return $this->getInt('priority');
    }

    public function getCorrelationId(): string|null
    {
        return $this->getString('correlation_id');
    }

    public function getReplyTo(): string|null
    {
        return $this->getString('reply_to');
    }

    public function getExpiration(): int|null
    {
        return $this->getInt('expiration');
    }

    public function getMessageId(): string|null
    {
        return $this->getString('message_id');
    }

    public function getTimestamp(): int|null
    {
        return $this->getInt('timestamp');
    }

    public function getType(): string|null
    {
        return $this->getString('type');
    }

    public function getUserId(): string|null
    {
        return $this->getString('user_id');
    }

    public function getAppId(): string|null
    {
        return $this->getString('app_id');
    }

    public function getClusterId(): string|null
    {
        return $this->getString('cluster_id');
    }

    private function get(string $key): mixed
    {
        try {
            return $this->amqpMessage->get($key);
        } catch (OutOfBoundsException) {
            return null;
        }
    }

    private function getString(string $key): string|null
    {
        /** @var mixed $value */
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }

    private function getInt(string $key): int|null
    {
        /** @var mixed $value */
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
