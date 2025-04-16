<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class AmqpReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private AmqpEnvelope $amqpEnvelope,
        private string $queueName,
    ) {
    }

    public function getAMQPEnvelope(): AmqpEnvelope
    {
        return $this->amqpEnvelope;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }
}
