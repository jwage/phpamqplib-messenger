<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function count;
use function function_exists;
use function intdiv;
use function is_int;
use function max;
use function pcntl_signal_dispatch;
use function round;
use function spl_object_id;
use function stream_select;
use function usleep;

/**
 * Multiplexed AMQP wait used by phpamqplib messenger:consume workers.
 *
 * All-phpamqplib workers wait from get(): the first transport of a pass
 * stream_selects every registered socket, later transports only drain.
 * Mixed workers wait from AmqpWorkerListener after every receiver has been
 * polled (coalesce disabled) so Doctrine/Redis/etc. stay in the loop.
 */
class ConsumerWaitCoordinator
{
    /** @var array<int, Connection> */
    private array $connections = [];

    private bool $waitedThisPass = false;

    private float $waitFloor = 0.0;

    public function __construct(
        private Debug $debug = new Debug(),
    ) {
    }

    public function register(Connection $connection): void
    {
        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function unregister(Connection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
    }

    public function reset(): void
    {
        $this->waitedThisPass = false;
    }

    public function setWaitFloor(float $waitFloor): void
    {
        $this->waitFloor = max(0.0, $waitFloor);
        $this->debug->log('Wait floor set', ['wait_floor' => $this->waitFloor]);
    }

    public function wait(float $timeout, bool $coalesce = true): void
    {
        $timeout = max($timeout, $this->waitFloor);
        if ($coalesce && $this->waitedThisPass) {
            $this->debug->log('Coalesced wait; draining without selecting', [
                'timeout' => $timeout,
                'connections' => count($this->connections),
            ]);

            foreach ($this->connections as $connection) {
                $connection->drainConsumerChannel();
            }

            return;
        }

        if ($coalesce) {
            $this->waitedThisPass = true;
        }

        $this->selectAndDrain($timeout);
    }

    private function selectAndDrain(float $timeout): void
    {
        foreach ($this->connections as $connection) {
            try {
                $connection->keepalive();
            } catch (TransportException) {
            }
        }

        $hasDelivery = false;

        foreach ($this->connections as $connection) {
            $connection->drainConsumerChannel();

            if ($connection->hasBufferedDeliveries()) {
                $hasDelivery = true;
            }
        }

        /** @infection-ignore-all */
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        // Heartbeats and pending methods are drainable work, not deliveries.
        // Skipping select for those returns empty from get(), and Worker
        // leftover-sleeps --sleep between prefetch-1 messages.
        if ($hasDelivery) {
            $this->debug->log('Skipping stream_select; a delivery is already buffered', [
                'connections' => count($this->connections),
            ]);

            return;
        }

        /** @var array<int, resource|object> $read */
        $read    = [];
        $indexed = [];

        foreach ($this->connections as $connection) {
            $socket = $connection->getConsumerSocket();

            if ($socket === null) {
                continue;
            }

            $index           = spl_object_id($connection);
            $read[$index]    = $socket;
            $indexed[$index] = $connection;
        }

        if ($read === []) {
            $this->debug->log('No AMQP sockets to wait on', [
                'timeout' => $timeout,
                'connections' => count($this->connections),
            ]);

            // Registered connections with no socket (broker down) must still
            // block. Returning immediately plus messenger:consume --sleep=0
            // busy-loops start-failure warnings.
            if ($this->connections !== []) {
                $this->sleepWithoutSockets($timeout);
            }

            return;
        }

        $write        = null;
        $except       = null;
        $ready        = $read;
        [$sec, $usec] = $this->splitTimeout($timeout);

        $this->debug->log('Waiting on AMQP sockets', [
            'timeout' => $timeout,
            'seconds' => $sec,
            'microseconds' => $usec,
            'sockets' => count($read),
        ]);

        try {
            /** @psalm-suppress InvalidArgument */
            $selected = stream_select($ready, $write, $except, $sec, $usec);
        } catch (Throwable) {
            $selected = 0;
        }

        $this->debug->log('stream_select finished', [
            'selected' => $selected,
            'sockets' => count($read),
        ]);

        /** @infection-ignore-all */
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if (is_int($selected) && $selected > 0) {
            foreach ($indexed as $index => $connection) {
                if (isset($ready[$index])) {
                    $connection->drainConsumerChannel();
                }
            }

            return;
        }

        foreach ($indexed as $connection) {
            $connection->drainConsumerChannel();
        }
    }

    private function sleepWithoutSockets(float $timeout): void
    {
        usleep($this->timeoutToMicroseconds($timeout));
    }

    private function timeoutToMicroseconds(float $timeout): int
    {
        return (int) round($timeout * 1_000_000.0);
    }

    /** @return array{0: int, 1: int} */
    private function splitTimeout(float $timeout): array
    {
        $totalMicroseconds = $this->timeoutToMicroseconds($timeout);
        $seconds           = intdiv($totalMicroseconds, 1_000_000);
        $microseconds      = $totalMicroseconds % 1_000_000;

        return [$seconds, $microseconds];
    }
}
