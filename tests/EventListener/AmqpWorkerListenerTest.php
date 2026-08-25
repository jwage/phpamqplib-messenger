<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\EventListener;

use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

use function method_exists;
use function microtime;

class AmqpWorkerListenerTest extends TestCase
{
    public function testOnWorkerStartedEnablesExternalWaitForMatchingTransports(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('setWaitCoordinator');
        $connection->expects(self::once())
            ->method('listen')
            ->with(null);

        $other = $this->createMock(Connection::class);
        $other->expects(self::once())
            ->method('setWaitCoordinator');
        $other->expects(self::never())
            ->method('listen');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);
        $listener->addConnection('unused', $other);

        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async'])));
    }

    public function testOnWorkerRunningDoesNotWaitWhenTheWorkerIsNotIdle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));
    }

    public function testOnWorkerRunningWaitsWithTheConnectionTimeoutWhenIdle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(1.5);
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(1.5);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsNotCappedBySleepWhenEveryTransportIsPhpAmqpLib(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(5.0);
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(5.0);

        $other = $this->createMock(Connection::class);
        $other->method('listen');
        $other->method('getWaitTimeout')
            ->willReturn(5.0);
        $other->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('high', $connection);
        $listener->addConnection('low', $other);

        $worker = $this->createWorker(['high', 'low']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 1_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsCappedBySleepWhenNonPhpAmqpLibTransportsArePresent(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            self::markTestSkipped('WorkerStartedEvent::getIdleTimeout() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(5.0);
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(2.0);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 2_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsCappedByTheWorkerDeadline(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getDeadline')) {
            self::markTestSkipped('WorkerStartedEvent::getDeadline() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(60.0);
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(self::callback(static function (float $timeout): bool {
                return $timeout > 0.0 && $timeout <= 2.5;
            }));

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, deadline: microtime(true) + 2.0));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsSkippedWhenTheWorkerDeadlineHasPassed(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getDeadline')) {
            self::markTestSkipped('WorkerStartedEvent::getDeadline() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(60.0);
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, deadline: microtime(true) - 1.0));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testOnWorkerStoppedDisablesExternalWait(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('listen');
        $connection->expects(self::once())
            ->method('disableExternalWait');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);
        $coordinator->expects(self::once())
            ->method('unregister')
            ->with($connection);

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerStopped(new WorkerStoppedEvent($worker));
    }

    /** @param list<string> $transportNames */
    private function createWorker(array $transportNames): Worker
    {
        $receivers = [];

        foreach ($transportNames as $transportName) {
            $receivers[$transportName] = $this->createStub(ReceiverInterface::class);
        }

        return new Worker(
            $receivers,
            $this->createStub(MessageBusInterface::class),
        );
    }

    private function createWorkerStartedEvent(
        Worker $worker,
        float|null $deadline = null,
        int $idleTimeout = 1_000_000,
    ): WorkerStartedEvent {
        if (method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            return new WorkerStartedEvent($worker, $deadline, $idleTimeout);
        }

        return new WorkerStartedEvent($worker);
    }
}
