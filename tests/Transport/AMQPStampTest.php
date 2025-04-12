<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPStamp;
use PhpAmqpLib\Message\AMQPMessage;

class AMQPStampTest extends TestCase
{
    private AMQPStamp $stamp;

    public function testCreateFromAMQPEnvelope(): void
    {
        $amqpEnvelope = new AMQPEnvelope(new AMQPMessage('test'));

        $stamp = AMQPStamp::createFromAMQPEnvelope(amqpEnvelope: $amqpEnvelope, retryRoutingKey: 'test');

        self::assertSame('test', $stamp->getRoutingKey());

        self::assertSame([
            'headers' => [],
            'content_type' => null,
            'content_encoding' => null,
            'delivery_mode' => null,
            'priority' => null,
            'timestamp' => null,
            'app_id' => null,
            'message_id' => null,
            'user_id' => null,
            'expiration' => null,
            'type' => null,
            'reply_to' => null,
            'correlation_id' => null,
        ], $stamp->getAttributes());

        self::assertTrue($stamp->isRetryAttempt());
    }

    public function testCreateFromAMQPEnvelopeWithoutRetryRoutingKey(): void
    {
        $amqpEnvelope = new AMQPEnvelope(new AMQPMessage('test'));

        $stamp = AMQPStamp::createFromAMQPEnvelope(amqpEnvelope: $amqpEnvelope);

        self::assertNull($stamp->getRoutingKey());
        self::assertFalse($stamp->isRetryAttempt());
    }

    public function testGetRoutingKey(): void
    {
        self::assertSame('test', $this->stamp->getRoutingKey());
    }

    public function testGetAttributes(): void
    {
        self::assertSame(['test' => true], $this->stamp->getAttributes());
    }

    public function testIsRetryAttempt(): void
    {
        self::assertFalse($this->stamp->isRetryAttempt());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stamp = new AMQPStamp('test', ['test' => true]);
    }
}
