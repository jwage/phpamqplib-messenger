<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Stamp\StampInterface;

class AMQPBatchStamp implements StampInterface
{
    public function __construct(
        private int $batchSize = 100,
    ) {
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }
}
