<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Retry;

class RecordingRetry extends Retry
{
    /** @var list<int> */
    public array $sleeps = [];

    protected function sleep(int $microseconds): void
    {
        $this->sleeps[] = $microseconds;

        parent::sleep($microseconds);
    }
}
