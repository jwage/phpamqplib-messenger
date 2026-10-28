<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Message;

class ConfirmMessage
{
    public function __construct(
        public int $count,
    ) {
    }
}
