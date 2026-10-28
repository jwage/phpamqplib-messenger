<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

class E2eTxMessage
{
    public function __construct(
        public string $id,
    ) {
    }
}
