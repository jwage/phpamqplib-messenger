<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use OutOfBoundsException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;

class AmqpEnvelopeTest extends TestCase
{
    /** @return array{AMQPMessage&MockObject, AmqpEnvelope} */
    private function createMockEnvelope(): array
    {
        $message = $this->createMock(AMQPMessage::class);

        return [$message, new AmqpEnvelope($message)];
    }

    public function testGetAMQPMessage(): void
    {
        $message  = $this->createStub(AMQPMessage::class);
        $envelope = new AmqpEnvelope($message);

        self::assertSame($message, $envelope->getAMQPMessage());
    }

    public function testGetAttributes(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get_properties')
            ->willReturn(['test' => 'abc', 'other' => 'def']);

        self::assertSame(['test' => 'abc', 'other' => 'def'], $envelope->getAttributes());
    }

    public function testAck(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('ack');

        $envelope->ack();
    }

    public function testNack(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('nack');

        $envelope->nack();
    }

    public function testGetBody(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('getBody')
            ->willReturn('test body');

        self::assertSame('test body', $envelope->getBody());
    }

    public function testGetRoutingKey(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('getRoutingKey')
            ->willReturn('test routing key');

        self::assertSame('test routing key', $envelope->getRoutingKey());
    }

    public function testGetContentType(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('content_type')
            ->willReturn('test content type');

        self::assertSame('test content type', $envelope->getContentType());
    }

    public function testGetContentEncoding(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('getContentEncoding')
            ->willReturn('test content encoding');

        self::assertSame('test content encoding', $envelope->getContentEncoding());
    }

    public function testGetHeaders(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $headers = new AMQPTable(['test' => 1, 'other' => 2]);

        $message->expects(self::once())
            ->method('get')
            ->with('application_headers')
            ->willReturn($headers);

        self::assertSame(['test' => 1, 'other' => 2], $envelope->getHeaders());
    }

    public function testGetHeadersEmpty(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('application_headers')
            ->willReturn(null);

        self::assertSame([], $envelope->getHeaders());
    }

    public function testGetDeliveryMode(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('delivery_mode')
            ->willReturn(AMQPMessage::DELIVERY_MODE_PERSISTENT);

        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $envelope->getDeliveryMode());
    }

    public function testGetPriority(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('priority')
            ->willReturn(1);

        self::assertSame(1, $envelope->getPriority());
    }

    public function testGetPriorityCastsNumericStringsToInt(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('priority')
            ->willReturn('5');

        $priority = $envelope->getPriority();

        self::assertSame(5, $priority);
    }

    public function testGetCorrelationId(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('correlation_id')
            ->willReturn('123');

        self::assertSame('123', $envelope->getCorrelationId());
    }

    public function testGetReplyTo(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('reply_to')
            ->willReturn('test reply to');

        self::assertSame('test reply to', $envelope->getReplyTo());
    }

    public function testGetExpiration(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('expiration')
            ->willReturn(123);

        self::assertSame(123, $envelope->getExpiration());
    }

    public function testGetMessageId(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('message_id')
            ->willReturn('test message id');

        self::assertSame('test message id', $envelope->getMessageId());
    }

    public function testGetTimestamp(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('timestamp')
            ->willReturn(123);

        self::assertSame(123, $envelope->getTimestamp());
    }

    public function getType(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('type')
            ->willReturn('test type');

        self::assertSame('test type', $envelope->getType());
    }

    public function testGetUserId(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('user_id')
            ->willReturn('test user id');

        self::assertSame('test user id', $envelope->getUserId());
    }

    public function testGetAppId(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('app_id')
            ->willReturn('test app id');

        self::assertSame('test app id', $envelope->getAppId());
    }

    public function testGetClusterId(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('cluster_id')
            ->willReturn('test cluster id');

        self::assertSame('test cluster id', $envelope->getClusterId());
    }

    public function testGetReturnsNullIfPropertyDoesNotExist(): void
    {
        [$message, $envelope] = $this->createMockEnvelope();

        $message->expects(self::once())
            ->method('get')
            ->with('type')
            ->willReturn($this->throwException(new OutOfBoundsException()));

        self::assertNull($envelope->getType());
    }

    public function testWithRealAMQPMessage(): void
    {
        $message = new AMQPMessage(
            'test body',
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['foo' => 'bar']),
            ],
        );

        $envelope = new AmqpEnvelope($message);

        self::assertSame('test body', $envelope->getBody());
        self::assertSame('text/plain', $envelope->getContentType());
        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $envelope->getDeliveryMode());
        self::assertSame(['foo' => 'bar'], $envelope->getHeaders());
    }
}
