<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\EventListener;

use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

use function method_exists;
use function microtime;

class AmqpWorkerListenerTest extends TestCase
{
    public function testOnWorkerStartedStartsConsumersForMatchingPhpAmqpLibTransports(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('setWaitCoordinator');
        $connection->expects(self::once())
            ->method('startConsumers')
            ->with(null);
        $connection->expects(self::never())
            ->method('listen');

        $other = $this->createMock(Connection::class);
        $other->expects(self::once())
            ->method('setWaitCoordinator');
        $other->expects(self::never())
            ->method('startConsumers');
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

    public function testOnWorkerStartedListensWhenNonPhpAmqpLibTransportsArePresent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('listen')
            ->with(null);
        $connection->expects(self::never())
            ->method('startConsumers');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async', 'redis'])));
    }

    public function testOnWorkerRunningResetsTheCoordinatorWhenEveryTransportIsPhpAmqpLib(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('startConsumers');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('reset');

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));
    }

    public function testOnWorkerRunningDoesNotWaitWhenTheWorkerIsNotIdle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(5.0);
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));
    }

    public function testOnWorkerRunningWaitsWithTheConnectionTimeoutWhenIdleAndMixed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(0.5);
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(0.5, false);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 2_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsNotUsedWhenEveryTransportIsPhpAmqpLib(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('startConsumers');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $other = $this->createMock(Connection::class);
        $other->method('startConsumers');
        $other->expects(self::never())
            ->method('waitForDeliveries');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('reset');

        $listener = new AmqpWorkerListener($coordinator);
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
            ->with(2.0, false);

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
            }), false);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, deadline: microtime(true) + 2.0, idleTimeout: 10_000_000));
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

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, deadline: microtime(true) - 1.0));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testOnWorkerStoppedDisablesExternalWait(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('startConsumers');
        $connection->expects(self::once())
            ->method('disableExternalWait');
        $connection->expects(self::once())
            ->method('unregisterFromWaitCoordinator');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerStopped(new WorkerStoppedEvent($worker));
    }

    public function testOnWorkerStartedMatchesPhpAmqpLibTransportsAfterUnmatchedOnes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('listen');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['redis', 'async'])));
    }

    public function testOnWorkerStartedSurvivesATransportThatCannotConnect(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('startConsumers')
            ->willThrowException(new TransportException('connection refused'));

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async'])));
    }

    public function testIdleWaitUsesTheFirstMatchedPhpAmqpLibTransport(): void
    {
        $first = $this->createMock(Connection::class);
        $first->method('listen');
        $first->method('getWaitTimeout')
            ->willReturn(0.4);
        $first->expects(self::once())
            ->method('waitForDeliveries')
            ->with(0.4, false);

        $second = $this->createMock(Connection::class);
        $second->method('listen');
        $second->method('getWaitTimeout')
            ->willReturn(9.0);
        $second->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('high', $first);
        $listener->addConnection('low', $second);

        $worker = $this->createWorker(['high', 'low', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 2_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsSkippedWhenSleepCapIsZero(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            self::markTestSkipped('WorkerStartedEvent::getIdleTimeout() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->method('getWaitTimeout')
            ->willReturn(5.0);
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 0));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleTimeoutFallsBackWhenTheEventDoesNotExposeIt(): void
    {
        $method   = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));

        self::assertSame(1.0, $method->invoke($listener, new stdClass()));
    }

    public function testIdleTimeoutFallsBackWhenTheValueIsNotNumeric(): void
    {
        $event = new class {
            public function getIdleTimeout(): string
            {
                return 'nope';
            }
        };

        $method   = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));

        self::assertSame(1.0, $method->invoke($listener, $event));
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
        $reflection  = new ReflectionClass(WorkerStartedEvent::class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfParameters() >= 3) {
            return $reflection->newInstance($worker, $deadline, $idleTimeout);
        }

        return new WorkerStartedEvent($worker);
    }
}
