<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use OutOfBoundsException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;

class AmqpEnvelopeTest extends TestCase
{
    /** @var MockObject&AMQPMessage */
    private AMQPMessage $message;

    private AmqpEnvelope $envelope;

    public function testGetAMQPMessage(): void
    {
        self::assertSame($this->message, $this->envelope->getAMQPMessage());
    }

    public function testGetAttributes(): void
    {
        $this->message->expects(self::once())
            ->method('get_properties')
            ->willReturn(['test' => 'abc']);

        self::assertSame(['test' => 'abc'], $this->envelope->getAttributes());
    }

    public function testAck(): void
    {
        $this->message->expects(self::once())
            ->method('ack');

        $this->envelope->ack();
    }

    public function testNack(): void
    {
        $this->message->expects(self::once())
            ->method('nack');

        $this->envelope->nack();
    }

    public function testGetBody(): void
    {
        $this->message->expects(self::once())
            ->method('getBody')
            ->willReturn('test body');

        self::assertSame('test body', $this->envelope->getBody());
    }

    public function testGetRoutingKey(): void
    {
        $this->message->expects(self::once())
            ->method('getRoutingKey')
            ->willReturn('test routing key');

        self::assertSame('test routing key', $this->envelope->getRoutingKey());
    }

    public function testGetContentType(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('content_type')
            ->willReturn('test content type');

        self::assertSame('test content type', $this->envelope->getContentType());
    }

    public function testGetContentEncoding(): void
    {
        $this->message->expects(self::once())
            ->method('getContentEncoding')
            ->willReturn('test content encoding');

        self::assertSame('test content encoding', $this->envelope->getContentEncoding());
    }

    public function testGetHeaders(): void
    {
        $headers = new AMQPTable(['test' => 1]);

        $this->message->expects(self::once())
            ->method('get')
            ->with('application_headers')
            ->willReturn($headers);

        self::assertSame(['test' => 1], $this->envelope->getHeaders());
    }

    public function testGetHeadersEmpty(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('application_headers')
            ->willReturn(null);

        self::assertSame([], $this->envelope->getHeaders());
    }

    public function testGetDeliveryMode(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('delivery_mode')
            ->willReturn(AMQPMessage::DELIVERY_MODE_PERSISTENT);

        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $this->envelope->getDeliveryMode());
    }

    public function testGetPriority(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('priority')
            ->willReturn(1);

        self::assertSame(1, $this->envelope->getPriority());
    }

    public function testGetCorrelationId(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('correlation_id')
            ->willReturn('123');

        self::assertSame('123', $this->envelope->getCorrelationId());
    }

    public function testGetReplyTo(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('reply_to')
            ->willReturn('test reply to');

        self::assertSame('test reply to', $this->envelope->getReplyTo());
    }

    public function testGetExpiration(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('expiration')
            ->willReturn(123);

        self::assertSame(123, $this->envelope->getExpiration());
    }

    public function testGetMessageId(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('message_id')
            ->willReturn('test message id');

        self::assertSame('test message id', $this->envelope->getMessageId());
    }

    public function testGetTimestamp(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('timestamp')
            ->willReturn(123);

        self::assertSame(123, $this->envelope->getTimestamp());
    }

    public function getType(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('type')
            ->willReturn('test type');

        self::assertSame('test type', $this->envelope->getType());
    }

    public function testGetUserId(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('user_id')
            ->willReturn('test user id');

        self::assertSame('test user id', $this->envelope->getUserId());
    }

    public function testGetAppId(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('app_id')
            ->willReturn('test app id');

        self::assertSame('test app id', $this->envelope->getAppId());
    }

    public function testGetClusterId(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('cluster_id')
            ->willReturn('test cluster id');

        self::assertSame('test cluster id', $this->envelope->getClusterId());
    }

    public function testGetReturnsNullIfPropertyDoesNotExist(): void
    {
        $this->message->expects(self::once())
            ->method('get')
            ->with('type')
            ->willReturn($this->throwException(new OutOfBoundsException()));

        self::assertNull($this->envelope->getType());
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->message = $this->createMock(AMQPMessage::class);

        $this->envelope = new AmqpEnvelope($this->message);
    }
}
