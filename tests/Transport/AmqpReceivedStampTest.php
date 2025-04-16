<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use PhpAmqpLib\Message\AMQPMessage;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->message = new AMQPMessage('test message');

        $this->envelope = new AmqpEnvelope($this->message);

        $this->stamp = new AmqpReceivedStamp($this->envelope, 'queue_name');
    }
}
