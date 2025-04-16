<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

class Deferable implements StampInterface
{
    public function __construct(
        private int $batchSize,
    ) {
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }
}
