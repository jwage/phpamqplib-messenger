<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

interface BatchSenderInterface
{
    public function flush(): void;
}
