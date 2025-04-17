<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Stamp;

use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class Deferred implements NonSendableStampInterface
{
    public function __construct(
        private BatchTransportInterface $transport,
    ) {
    }

    public function getTransport(): BatchTransportInterface
    {
        return $this->transport;
    }
}
