<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

use function microtime;

#[Group('live')]
class MultiTransportWorkerTest extends TestCase
{
    private Harness $harness;

    public function testIdleWaitIsNotSerializedAcrossTransports(): void
    {
        $waitTimeout             = 0.5;
        [$high, $low, $listener] = $this->createWorkerTransports($waitTimeout);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);

        $worker = new Worker(
            ['high' => $high, 'low' => $low],
            $this->createBus(),
            $dispatcher,
        );

        $dispatcher->addListener(
            WorkerRunningEvent::class,
            static function (WorkerRunningEvent $event) use ($worker): void {
                if ($event->isWorkerIdle()) {
                    $worker->stop();
                }
            },
        );

        $started = microtime(true);
        $worker->run(['sleep' => 0]);
        $elapsed = microtime(true) - $started;

        // Sequential blocking waits would be ~2 × wait_timeout. One idle wait
        // after every transport has been polled should stay well under that.
        self::assertLessThan($waitTimeout * 1.7, $elapsed, 'Idle wait scaled with the number of transports');
        self::assertGreaterThan($waitTimeout * 0.4, $elapsed, 'Worker returned before waiting for deliveries');
    }

    public function testMessageOnTheSecondTransportWakesWithoutWaitingTheFirstTimeout(): void
    {
        $waitTimeout             = 0.8;
        [$high, $low, $listener] = $this->createWorkerTransports($waitTimeout);

        $low->send(new Envelope(new stdClass()));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);

        $worker = new Worker(
            ['high' => $high, 'low' => $low],
            $this->createBus(),
            $dispatcher,
        );

        $dispatcher->addListener(
            WorkerMessageHandledEvent::class,
            static function () use ($worker): void {
                $worker->stop();
            },
        );

        $started = microtime(true);
        $worker->run(['sleep' => 0]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(
            $waitTimeout * 0.6,
            $elapsed,
            'Second-transport message waited for the first transport timeout',
        );
    }

    public function testIdleWaitDoesNotStarveANonPhpAmqpLibReceiver(): void
    {
        $waitTimeout = 2.0;
        $name        = $this->harness->topologyName('mixed_amqp');
        $connection  = $this->harness->connect($this->harness->topology(
            $name,
            ['wait_timeout' => $waitTimeout],
            ['wait_timeout' => $waitTimeout],
        ));
        $connection->setup();

        $amqp     = new AmqpTransport($connection, serializer: new PhpSerializer());
        $listener = new AmqpWorkerListener(new ConsumerWaitCoordinator());
        $listener->addConnection('amqp', $connection);

        $polls   = 0;
        $started = microtime(true);
        $other   = $this->createStub(ReceiverInterface::class);
        $other->method('get')
            ->willReturnCallback(static function () use (&$polls): array {
                ++$polls;

                return [];
            });

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);

        $worker = new Worker(
            ['amqp' => $amqp, 'other' => $other],
            $this->createBus(),
            $dispatcher,
        );

        $dispatcher->addListener(
            WorkerRunningEvent::class,
            static function (WorkerRunningEvent $event) use ($worker, &$polls, $started): void {
                if (! $event->isWorkerIdle()) {
                    return;
                }

                // Symfony < 8.1 Worker has no time_limit; stop after enough polls.
                if ($polls > 2 || microtime(true) - $started > 4.0) {
                    $worker->stop();
                }
            },
        );

        $worker->run(['sleep' => 150_000]);
        $elapsed = microtime(true) - $started;

        self::assertGreaterThan(
            2,
            $polls,
            'Non-phpamqplib receiver was starved by the AMQP idle wait',
        );
        self::assertLessThan(
            $waitTimeout * 2.5,
            $elapsed,
            'Idle wait used the full AMQP wait_timeout instead of --sleep',
        );
    }

    public function testMixedIdleWaitWakesDuringLeftoverSleep(): void
    {
        $waitTimeout = 0.25;
        $delayMs     = 400;
        $name        = $this->harness->topologyName('mixed_leftover');
        $connection  = $this->harness->connect($this->harness->topology(
            $name,
            ['wait_timeout' => $waitTimeout],
            [
                'wait_timeout' => $waitTimeout,
                'confirm_enabled' => false,
                'delay' => [
                    'enabled' => true,
                    'auto_setup' => true,
                    'exchange' => ['name' => $name . '_delays'],
                    'queue_name_pattern' => $name . '_%delay%',
                ],
            ],
        ));
        $connection->setup();

        $amqp     = new AmqpTransport($connection, serializer: new PhpSerializer());
        $listener = new AmqpWorkerListener(new ConsumerWaitCoordinator());
        $listener->addConnection('amqp', $connection);

        $other = $this->createStub(ReceiverInterface::class);
        $other->method('get')->willReturn([]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);

        $worker = new Worker(
            ['amqp' => $amqp, 'other' => $other],
            $this->createBus(),
            $dispatcher,
        );

        $handled = 0;
        $dispatcher->addListener(
            WorkerMessageHandledEvent::class,
            static function () use ($worker, &$handled): void {
                ++$handled;
                $worker->stop();
            },
        );

        $amqp->send(new Envelope(new stdClass(), [new DelayStamp($delayMs)]));

        $started = microtime(true);
        $dispatcher->addListener(
            WorkerRunningEvent::class,
            static function () use ($worker, $started): void {
                if (microtime(true) - $started > 3.0) {
                    $worker->stop();
                }
            },
        );

        $worker->run(['sleep' => 1_000_000]);
        $elapsed = microtime(true) - $started;

        self::assertSame(1, $handled, 'Delayed AMQP message was not consumed');
        self::assertGreaterThan(
            $delayMs / 1000 - 0.1,
            $elapsed,
            'Delayed message was consumed before the delay elapsed',
        );
        self::assertLessThan(
            0.85,
            $elapsed,
            'AMQP delivery waited for leftover Worker --sleep instead of waking stream_select',
        );
    }

    public function testIdleWaitHonorsAShorterPerQueueTimeout(): void
    {
        $name       = $this->harness->topologyName('queue_wait');
        $connection = $this->harness->connect($this->harness->topology(
            $name,
            ['wait_timeout' => 0.3],
            ['wait_timeout' => 2.0],
        ));
        $connection->setup();

        $transport = new AmqpTransport($connection, serializer: new PhpSerializer());
        $listener  = new AmqpWorkerListener(new ConsumerWaitCoordinator());
        $listener->addConnection('async', $connection);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);

        $worker = new Worker(
            ['async' => $transport],
            $this->createBus(),
            $dispatcher,
        );

        $dispatcher->addListener(
            WorkerRunningEvent::class,
            static function (WorkerRunningEvent $event) use ($worker): void {
                if ($event->isWorkerIdle()) {
                    $worker->stop();
                }
            },
        );

        $started = microtime(true);
        $worker->run(['sleep' => 0]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.0, $elapsed, 'Idle wait used the connection wait_timeout instead of the queue override');
        self::assertGreaterThan(0.15, $elapsed, 'Worker returned before waiting for deliveries');
    }

    public function testWorkerDoesNotCrashWhenTheBrokerIsUnreachableAtStart(): void
    {
        $name       = $this->harness->topologyName('down_start');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'host' => '127.0.0.1',
            'port' => 1,
            'retries' => 0,
            'retry_wait_time' => 0,
            'connect_timeout' => 0.2,
            'read_timeout' => 0.2,
            'write_timeout' => 0.2,
        ]));
        $transport  = new AmqpTransport($connection);
        $listener   = new AmqpWorkerListener(new ConsumerWaitCoordinator());
        $listener->addConnection('async', $connection);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);

        $worker = new Worker(
            ['async' => $transport],
            $this->createBus(),
            $dispatcher,
        );

        $dispatcher->addListener(
            WorkerRunningEvent::class,
            static function () use ($worker): void {
                $worker->stop();
            },
        );

        $worker->run(['sleep' => 0]);

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new Harness();
    }

    protected function tearDown(): void
    {
        $this->harness->cleanup();

        parent::tearDown();
    }

    /** @return array{0: AmqpTransport, 1: AmqpTransport, 2: AmqpWorkerListener} */
    private function createWorkerTransports(float $waitTimeout): array
    {
        $highName = $this->harness->topologyName('idle_high');
        $lowName  = $this->harness->topologyName('idle_low');

        $highConnection = $this->harness->connect($this->harness->topology($highName, ['wait_timeout' => $waitTimeout], ['wait_timeout' => $waitTimeout]));
        $lowConnection  = $this->harness->connect($this->harness->topology($lowName, ['wait_timeout' => $waitTimeout], ['wait_timeout' => $waitTimeout]));

        $highConnection->setup();
        $lowConnection->setup();

        $serializer = new PhpSerializer();
        $high       = new AmqpTransport($highConnection, serializer: $serializer);
        $low        = new AmqpTransport($lowConnection, serializer: $serializer);

        $listener = new AmqpWorkerListener(new ConsumerWaitCoordinator());
        $listener->addConnection('high', $highConnection);
        $listener->addConnection('low', $lowConnection);

        return [$high, $low, $listener];
    }

    private function createBus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $message): Envelope {
                return $message instanceof Envelope ? $message : Envelope::wrap($message);
            });

        return $bus;
    }
}
