<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Transport\TransportInterface;

interface BatchTransportInterface extends TransportInterface
{
    public function flush(): void;
}
