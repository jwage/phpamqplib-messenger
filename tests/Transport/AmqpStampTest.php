<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use ReflectionProperty;

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

    public function testCreateFromAMQPEnvelopeUsesEnvelopeRoutingKeyWhenPreviousStampHasNone(): void
    {
        $message = new AMQPMessage('test');
        (new ReflectionProperty(AMQPMessage::class, 'routingKey'))->setValue($message, 'from-envelope');

        $stamp = AmqpStamp::createFromAMQPEnvelope(
            new AmqpEnvelope($message),
            new AmqpStamp(),
        );

        self::assertSame('from-envelope', $stamp->getRoutingKey());
    }

    public function testCreateFromAMQPEnvelopePrefersPreviousStampRoutingKeyOverEnvelope(): void
    {
        $message = new AMQPMessage('test');
        (new ReflectionProperty(AMQPMessage::class, 'routingKey'))->setValue($message, 'from-envelope');

        $stamp = AmqpStamp::createFromAMQPEnvelope(
            new AmqpEnvelope($message),
            new AmqpStamp('from-previous'),
        );

        self::assertSame('from-previous', $stamp->getRoutingKey());
    }

    public function testCreateFromAMQPEnvelopeKeepsPreviousStampAttributes(): void
    {
        $previousStamp = new AmqpStamp('previous-key', [
            'headers' => ['kept' => 'yes'],
            'content_type' => 'application/json',
            'content_encoding' => 'identity',
            'delivery_mode' => 1,
            'priority' => 1,
            'timestamp' => 111,
            'app_id' => 'previous-app',
            'message_id' => 'previous-id',
            'user_id' => 'previous-user',
            'expiration' => 10,
            'type' => 'previous-type',
            'reply_to' => 'previous-reply',
            'correlation_id' => 'previous-corr',
        ]);

        $amqpEnvelope = new AmqpEnvelope(new AMQPMessage('test', [
            'content_type' => 'text/plain',
            'content_encoding' => 'gzip',
            'delivery_mode' => 2,
            'priority' => 9,
            'timestamp' => 222,
            'app_id' => 'envelope-app',
            'message_id' => 'envelope-id',
            'user_id' => 'envelope-user',
            'expiration' => 99,
            'type' => 'envelope-type',
            'reply_to' => 'envelope-reply',
            'correlation_id' => 'envelope-corr',
            'application_headers' => new AMQPTable(['from' => 'envelope']),
        ]));

        $stamp = AmqpStamp::createFromAMQPEnvelope($amqpEnvelope, $previousStamp);

        self::assertSame('previous-key', $stamp->getRoutingKey());
        self::assertSame([
            'headers' => ['kept' => 'yes'],
            'content_type' => 'application/json',
            'content_encoding' => 'identity',
            'delivery_mode' => 1,
            'priority' => 1,
            'timestamp' => 111,
            'app_id' => 'previous-app',
            'message_id' => 'previous-id',
            'user_id' => 'previous-user',
            'expiration' => 10,
            'type' => 'previous-type',
            'reply_to' => 'previous-reply',
            'correlation_id' => 'previous-corr',
        ], $stamp->getAttributes());
    }

    public function testCreateWithAttributes(): void
    {
        $stamp = AmqpStamp::createWithAttributes(['test' => true]);

        self::assertSame(['test' => true], $stamp->getAttributes());
    }

    public function testCreateWithAttributesAndPreviousStamp(): void
    {
        $stamp = AmqpStamp::createWithAttributes(
            ['added' => true],
            new AmqpStamp('routing_key', ['kept' => 'yes']),
        );

        self::assertSame('routing_key', $stamp->getRoutingKey());
        self::assertSame(['kept' => 'yes', 'added' => true], $stamp->getAttributes());
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
