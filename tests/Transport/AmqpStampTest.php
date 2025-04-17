<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpStampTest extends TestCase
{
    private AmqpStamp $stamp;

    public function testCreateFromAMQPEnvelope(): void
    {
        $amqpEnvelope = new AmqpEnvelope(new AMQPMessage('test'));

        $stamp = AmqpStamp::createFromAMQPEnvelope(amqpEnvelope: $amqpEnvelope, retryRoutingKey: 'test');

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
        $amqpEnvelope = new AmqpEnvelope(new AMQPMessage('test'));

        $stamp = AmqpStamp::createFromAMQPEnvelope(amqpEnvelope: $amqpEnvelope);

        self::assertNull($stamp->getRoutingKey());
        self::assertFalse($stamp->isRetryAttempt());
    }

    public function testCreateWithAttributes(): void
    {
        $stamp = AmqpStamp::createWithAttributes(['test' => true]);

        self::assertSame(['test' => true], $stamp->getAttributes());
    }

    public function testCreateWithAttributesAndPreviousStamp(): void
    {
        $stamp = AmqpStamp::createWithAttributes(
            ['test1' => true, 'test2' => false],
            new AmqpStamp('routing_key', ['test1' => true, 'test2' => false]),
        );

        self::assertSame('routing_key', $stamp->getRoutingKey());
        self::assertSame(['test1' => true, 'test2' => false], $stamp->getAttributes());
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

        $this->stamp = new AmqpStamp('test', ['test' => true]);
    }
}
