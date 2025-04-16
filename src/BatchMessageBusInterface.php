<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Symfony\Component\Messenger\MessageBusInterface;

interface BatchMessageBusInterface extends MessageBusInterface
{
    public function getBatch(int $batchSize): Batch;
}
