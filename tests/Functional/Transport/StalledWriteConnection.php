<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Functional\Transport;

use Closure;
use PhpAmqpLib\Connection\AMQPStreamConnection;

use function intdiv;
use function strlen;
use function substr;

/**
 * Simulates a signal handler firing between two fwrite() calls of a single frame by
 * invoking a hook after the first half of the next frame has been written.
 */
final class StalledWriteConnection extends AMQPStreamConnection
{
    public Closure|null $duringNextWrite = null;

    public int $reconnects = 0;

    public function reconnect(): void
    {
        $this->reconnects++;

        parent::reconnect();
    }

    public function write(mixed $data): void
    {
        $hook = $this->duringNextWrite;

        if ($hook === null || strlen((string) $data) < 2) {
            parent::write($data);

            return;
        }

        $this->duringNextWrite = null;
        $half                  = intdiv(strlen((string) $data), 2);

        $this->writing = true;

        try {
            $this->io->write(substr((string) $data, 0, $half));
            $hook();
            $this->io->write(substr((string) $data, $half));
        } finally {
            $this->writing = false;
        }
    }
}
