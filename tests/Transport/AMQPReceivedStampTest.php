<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPReceivedStamp;
use PhpAmqpLib\Message\AMQPMessage;

class AMQPReceivedStampTest extends TestCase
{
    private AMQPMessage $message;

    private AMQPEnvelope $envelope;

    private AMQPReceivedStamp $stamp;

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

        $this->envelope = new AMQPEnvelope($this->message);

        $this->stamp = new AMQPReceivedStamp($this->envelope, 'queue_name');
    }
}
