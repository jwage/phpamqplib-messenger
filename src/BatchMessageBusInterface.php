<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Symfony\Component\Messenger\MessageBusInterface;

interface BatchMessageBusInterface extends MessageBusInterface
{
    /** @param iterable<object|Envelope> $messages */
    public function dispatchBatches(iterable $messages, int $batchSize = 100): void;

    public function dispatchInBatch(object $message, int $batchSize): void;

    public function flush(): void;
}
