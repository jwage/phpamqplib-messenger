<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class AMQPStamp implements NonSendableStampInterface
{
    private bool $isRetryAttempt = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(
        private string|null $routingKey = null,
        private array $attributes = [],
    ) {
    }

    /** @psalm-suppress MixedAssignment */
    public static function createFromAMQPEnvelope(
        AMQPEnvelope $amqpEnvelope,
        self|null $previousStamp = null,
        string|null $retryRoutingKey = null,
    ): self {
        $attr = $previousStamp->attributes ?? [];

        $attr['headers']          ??= $amqpEnvelope->getHeaders();
        $attr['content_type']     ??= $amqpEnvelope->getContentType();
        $attr['content_encoding'] ??= $amqpEnvelope->getContentEncoding();
        $attr['delivery_mode']    ??= $amqpEnvelope->getDeliveryMode();
        $attr['priority']         ??= $amqpEnvelope->getPriority();
        $attr['timestamp']        ??= $amqpEnvelope->getTimestamp();
        $attr['app_id']           ??= $amqpEnvelope->getAppId();
        $attr['message_id']       ??= $amqpEnvelope->getMessageId();
        $attr['user_id']          ??= $amqpEnvelope->getUserId();
        $attr['expiration']       ??= $amqpEnvelope->getExpiration();
        $attr['type']             ??= $amqpEnvelope->getType();
        $attr['reply_to']         ??= $amqpEnvelope->getReplyTo();
        $attr['correlation_id']   ??= $amqpEnvelope->getCorrelationId();

        if ($retryRoutingKey === null) {
            $stamp = new self($previousStamp->routingKey ?? $amqpEnvelope->getRoutingKey(), $attr);
        } else {
            $stamp = new self($retryRoutingKey, $attr);

            $stamp->isRetryAttempt = true;
        }

        return $stamp;
    }

    public function getRoutingKey(): string|null
    {
        return $this->routingKey;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isRetryAttempt(): bool
    {
        return $this->isRetryAttempt;
    }
}
