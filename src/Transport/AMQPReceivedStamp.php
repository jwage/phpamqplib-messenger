<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class AMQPReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private AMQPEnvelope $amqpEnvelope,
        private string $queueName,
    ) {
    }

    public function getAMQPEnvelope(): AMQPEnvelope
    {
        return $this->amqpEnvelope;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }
}
