<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

interface BatchTransportInterface
{
    public function flush(): void;
}
