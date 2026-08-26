<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\EventListener;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Override;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\TransportException;

use function class_exists;
use function count;
use function is_callable;
use function is_float;
use function is_int;
use function is_numeric;
use function microtime;
use function min;

/**
 * Coordinates AMQP waits for messenger:consume so idle/SIGINT delay stays
 * close to one wait_timeout instead of scaling with the number of transports.
 *
 * All-phpamqplib workers wait inside get() (coalesced across sockets). That
 * way a delivery is yielded in the same worker iteration and leftover
 * --sleep does not delay the next message. Mixed workers match Symfony's
 * PostgreSqlNotifyOnIdleListener: get() only drains, and the wait happens
 * after every receiver has been polled, for --sleep, so leftover Worker
 * usleep does not run with no AMQP socket selected.
 */
class AmqpWorkerListener implements EventSubscriberInterface
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var list<Connection> */
    private array $matchedConnections = [];

    private Connection|null $activeConnection = null;

    private float|null $deadline = null;

    private float|null $sleepCap = null;

    private float|null $consoleIdleTimeoutSeconds = null;

    public function __construct(
        private ConsumerWaitCoordinator $coordinator,
    ) {
    }

    public function addConnection(string $transportName, Connection $connection): void
    {
        $this->connections[$transportName] = $connection;
        $connection->setWaitCoordinator($this->coordinator);
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($command === null || $command->getName() !== 'messenger:consume') {
            return;
        }

        $input = $event->getInput();

        if (! $input->hasOption('sleep')) {
            return;
        }

        try {
            $sleep = $input->getOption('sleep');
        } catch (InvalidArgumentException) {
            return;
        }

        if (! is_numeric($sleep)) {
            return;
        }

        $this->consoleIdleTimeoutSeconds = (float) $sleep;
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->activeConnection   = null;
        $this->matchedConnections = [];
        $this->deadline           = $this->getWorkerDeadline($event);

        /** @var list<string>|null $queueNames */
        $queueNames = $event->getWorker()->getMetadata()->getQueueNames();
        /** @var list<string> $allTransportNames */
        $allTransportNames = $event->getWorker()->getMetadata()->getTransportNames();

        $matched = [];

        foreach ($allTransportNames as $transportName) {
            $connection = $this->connections[$transportName] ?? null;

            if ($connection === null) {
                continue;
            }

            $matched[$transportName] = $connection;
        }

        // When non-phpamqplib transports are also consumed, cap the idle wait to
        // the worker's sleep duration so those transports are still polled regularly.
        $knownIdle = $this->getWorkerIdleTimeoutSeconds($event);

        if ($knownIdle === null) {
            $idleTimeout = 1.0;
            $waitFloor   = 0.0;
        } else {
            $idleTimeout = $knownIdle;
            $waitFloor   = $knownIdle;
        }

        $this->sleepCap = count($matched) < count($allTransportNames)
            ? $idleTimeout
            : null;
        $this->coordinator->setWaitFloor($this->sleepCap === null ? $waitFloor : 0.0);

        foreach ($matched as $connection) {
            try {
                if ($this->sleepCap !== null) {
                    $connection->listen($queueNames);
                } else {
                    $connection->startConsumers($queueNames);
                }
            } catch (TransportException) {
                // The next get() retries ensureStarted(); keep the worker running.
            }

            $this->coordinator->register($connection);
            $this->matchedConnections[] = $connection;
            $this->activeConnection   ??= $connection;
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->sleepCap === null) {
            $this->coordinator->reset();

            return;
        }

        $this->waitWhileIdle($event, $this->sleepCap);
    }

    public function onWorkerStopped(WorkerStoppedEvent $_event): void
    {
        foreach ($this->matchedConnections as $connection) {
            $connection->disableExternalWait();
            $connection->unregisterFromWaitCoordinator();
        }

        $this->matchedConnections = [];
        $this->activeConnection   = null;
        $this->deadline           = null;
        $this->sleepCap           = null;
        $this->coordinator->setWaitFloor(0.0);
    }

    /** @return array<string, string> */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        $events = [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND] = 'onConsoleCommand';
        }

        return $events;
    }

    /** @param object $event WorkerStartedEvent, or a stand-in without Symfony 8.1 methods. */
    private function getWorkerDeadline(object $event): float|null
    {
        $getter = [$event, 'getDeadline'];

        if (! is_callable($getter)) {
            return null;
        }

        /** @psalm-suppress MixedAssignment */
        $deadline = $getter();

        return is_float($deadline) ? $deadline : null;
    }

    /**
     * Worker sleep duration in seconds. Symfony 8.1+ exposes this as
     * WorkerStartedEvent::getIdleTimeout() (microseconds). Earlier versions
     * read messenger:consume --sleep from the console command. Mixed workers
     * fall back to the Worker default of 1 second; all-phpamqplib wait floor
     * stays 0 unless --sleep is known.
     *
     * @return float|null Seconds, or null when the worker did not expose --sleep.
     */
    private function getWorkerIdleTimeoutSeconds(object $event): float|null
    {
        $getter = [$event, 'getIdleTimeout'];

        if (is_callable($getter)) {
            /** @psalm-suppress MixedAssignment */
            $idleTimeout = $getter();

            if (is_int($idleTimeout) || is_float($idleTimeout)) {
                /** @psalm-suppress InvalidOperand */
                return $idleTimeout / 1_000_000.0;
            }
        }

        return $this->consoleIdleTimeoutSeconds;
    }

    private function waitWhileIdle(WorkerRunningEvent $event, float $sleepCap): void
    {
        if (! $event->isWorkerIdle() || $this->activeConnection === null) {
            return;
        }

        $timeout = $sleepCap;

        if ($this->deadline !== null) {
            $timeout = min($timeout, $this->deadline - microtime(true));
        }

        if ($timeout <= 0) {
            return;
        }

        $this->activeConnection->waitForDeliveries($timeout, coalesce: false);
    }
}
