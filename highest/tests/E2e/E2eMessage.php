<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

class E2eMessage
{
    public function __construct(
        public string $id,
        public bool $dispatchFollowup = false,
        public bool $dispatchToLow = false,
        public bool $dispatchToMemory = false,
        public int $sleepMs = 0,
        public int $failUntilAttempt = 0,
        public bool $unrecoverable = false,
    ) {
    }
}
