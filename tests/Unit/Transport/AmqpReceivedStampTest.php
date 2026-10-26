<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function serialize;
use function unserialize;

class AmqpReceivedStampTest extends TestCase
{
    private AMQPMessage $message;

    private AmqpEnvelope $envelope;

    private AmqpReceivedStamp $stamp;

    public function testGetAMQPEnvelope(): void
    {
        self::assertSame($this->envelope, $this->stamp->getAMQPEnvelope());
    }

    public function testGetQueueName(): void
    {
        self::assertSame('queue_name', $this->stamp->getQueueName());
    }

    public function testSerializeStampOfDeliveredMessage(): void
    {
        $this->message->setChannel($this->createStub(AMQPChannel::class));
        $this->message->setDeliveryInfo(1, false, 'exchange_name', 'routing_key');

        $copy = unserialize(serialize($this->stamp));

        self::assertInstanceOf(AmqpReceivedStamp::class, $copy);
        self::assertSame('queue_name', $copy->getQueueName());
        self::assertSame('test message', $copy->getAmqpEnvelope()->getBody());
        self::assertSame('routing_key', $copy->getAmqpEnvelope()->getRoutingKey());
        self::assertNull($copy->getAmqpEnvelope()->getAMQPMessage()->getChannel());
    }

    public function testSerializeMessengerEnvelopeCarryingTheStamp(): void
    {
        $this->message->setChannel($this->createStub(AMQPChannel::class));
        $this->message->setDeliveryInfo(7, false, 'exchange_name', 'routing_key');

        $envelope = new Envelope(new stdClass(), [$this->stamp, new DelayStamp(500), new AmqpStamp('routing_key')]);

        $copy = unserialize(serialize($envelope));

        self::assertInstanceOf(Envelope::class, $copy);

        $stamp = $copy->last(AmqpReceivedStamp::class);
        self::assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        self::assertSame('queue_name', $stamp->getQueueName());
        self::assertSame(7, $stamp->getAmqpEnvelope()->getAMQPMessage()->getDeliveryTag());
        self::assertNull($stamp->getAmqpEnvelope()->getAMQPMessage()->getChannel());
        self::assertSame(500, $copy->last(DelayStamp::class)?->getDelay());
        self::assertSame('routing_key', $copy->last(AmqpStamp::class)?->getRoutingKey());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->message = new AMQPMessage('test message');

        $this->envelope = new AmqpEnvelope($this->message);

        $this->stamp = new AmqpReceivedStamp($this->envelope, 'queue_name');
    }
}
