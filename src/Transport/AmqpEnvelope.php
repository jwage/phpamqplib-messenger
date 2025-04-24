<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use OutOfBoundsException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

use function assert;
use function is_numeric;
use function is_string;

class AmqpEnvelope
{
    public function __construct(
        private AMQPMessage $amqpMessage,
    ) {
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

    public function ack(): void
    {
        $this->amqpMessage->ack();
    }

    public function nack(): void
    {
        $this->amqpMessage->nack();
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
        return $this->amqpMessage->getContentEncoding();
    }

    /** @return array<string, mixed> */
    public function getHeaders(): array
    {
        $applicationHeaders = $this->get('application_headers');
        assert($applicationHeaders instanceof AMQPTable || $applicationHeaders === null);

        if ($applicationHeaders instanceof AMQPTable) {
            /** @var array<string, mixed> $headers */
            $headers = $applicationHeaders->getNativeData();

            return $headers;
        }

        return [];
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
