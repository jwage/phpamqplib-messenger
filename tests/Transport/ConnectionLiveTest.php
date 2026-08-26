<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Throwable;
use Traversable;

use function iterator_to_array;
use function microtime;
use function preg_replace;
use function sleep;
use function str_contains;
use function strtolower;
use function usleep;

#[Group('live')]
class ConnectionLiveTest extends TestCase
{
    private Harness $harness;

    public function testPublishAndFlushWithConfirmsDisabled(): void
    {
        $name       = $this->harness->topologyName('confirms_off');
        $connection = $this->harness->connect($this->harness->topology($name, extra: ['confirm_enabled' => false]));

        self::assertFalse($connection->getConfig()->confirmEnabled);

        $connection->publish(body: 'direct-one');
        self::assertSame(1, $this->harness->waitForMessageCount($connection, 1));

        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $connection->flush();

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertSame(3, $this->harness->waitForMessageCount($connection, 3));
    }

    public function testPublishRecoversAfterPublisherChannelClosesOnALiveConnection(): void
    {
        $name       = $this->harness->topologyName('pub_ch_close');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->setup();

        $publisherBefore = $connection->channel();
        self::assertInstanceOf(AMQPChannel::class, $publisherBefore);
        $publisherBefore->close();

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: 'after-channel-close');
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        $publisherAfter = $connection->channel();
        self::assertTrue($publisherAfter->is_open());
        self::assertNotSame($publisherBefore, $publisherAfter);
        self::assertSame(1, $connection->countMessagesInQueues());
    }

    public function testConsumeRecoversAfterConsumerChannelClosesOnALiveConnection(): void
    {
        $name       = $this->harness->topologyName('cons_ch_close');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));

        $connection->publish(body: 'before-consumer-close');

        $consumerBefore = $connection->consumerChannel();
        self::assertInstanceOf(AMQPChannel::class, $consumerBefore);
        $consumerBefore->close();

        $envelope = $this->harness->consumeOne($connection, $name);
        self::assertInstanceOf(AmqpEnvelope::class, $envelope);
        $envelope->ack();

        $consumerAfter = $connection->consumerChannel();
        self::assertTrue($consumerAfter->is_open());
        self::assertNotSame($consumerBefore, $consumerAfter);
        self::assertSame(0, $connection->countMessagesInQueues());
    }

    public function testConnectFailsWithTheWrongPassword(): void
    {
        $name   = $this->harness->topologyName('bad_auth');
        $badDsn = preg_replace('#://([^:@]+):([^@]+)@#', '://$1:wrong-password@', $this->harness->dsn());
        self::assertIsString($badDsn);
        self::assertNotSame($this->harness->dsn(), $badDsn);

        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'connect_timeout' => 2,
            'read_timeout' => 2,
            'write_timeout' => 2,
            'rpc_timeout' => 2,
        ]), $badDsn);

        try {
            $connection->setup();
            self::fail('Expected authentication to fail with the wrong password.');
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $haystack = strtolower($exception->getMessage() . ' ' . ($exception->getPrevious()?->getMessage() ?? ''));
            self::assertTrue(
                str_contains($haystack, 'refused')
                || str_contains($haystack, 'access_denied')
                || str_contains($haystack, 'login'),
                'wrong password should fail authentication, got: ' . $exception->getMessage(),
            );
        }
    }

    public function testPublishStillWorksAfterAnIdleHeartbeatInterval(): void
    {
        $name       = $this->harness->topologyName('heartbeat_idle');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'heartbeat' => 1,
            'read_timeout' => 10,
            'write_timeout' => 10,
            'rpc_timeout' => 10,
        ]));

        $connection->publish(body: 'before-idle');
        self::assertSame(1, $connection->countMessagesInQueues());

        sleep(3);

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: 'after-idle');
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertSame(2, $connection->countMessagesInQueues());
    }

    public function testDirectPublishNackFromOverflowIsNotRetried(): void
    {
        $name       = $this->harness->topologyName('live_nack');
        $connection = $this->harness->connect($this->harness->topology($name, [
            'arguments' => [
                'x-max-length' => 1,
                'x-overflow' => 'reject-publish',
            ],
        ]));

        $connection->publish(body: 'kept');
        self::assertSame(1, $connection->countMessagesInQueues());

        $elapsed = (float) $this->harness->withRetryDefaults(3, 500, function () use ($connection): float {
            return $this->harness->milliseconds(static function () use ($connection): void {
                try {
                    $connection->publish(body: 'rejected');
                    self::fail('Expected the overflowing publish to fail with a NACK');
                } catch (TransportException $exception) {
                    self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                }
            });
        });

        self::assertLessThan(400, $elapsed);
        self::assertSame(0, $this->harness->logger()->countRetryLogs());
        self::assertSame(1, $connection->countMessagesInQueues());
    }

    public function testBatchFlushNackFromOverflowKeepsTheBuffer(): void
    {
        $name       = $this->harness->topologyName('live_batch_nack');
        $connection = $this->harness->connect($this->harness->topology($name, [
            'arguments' => [
                'x-max-length' => 1,
                'x-overflow' => 'reject-publish',
            ],
        ]));

        $connection->publish(body: 'kept');
        self::assertSame(1, $connection->countMessagesInQueues());

        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $elapsed = (float) $this->harness->withRetryDefaults(3, 500, function () use ($connection): float {
            return $this->harness->milliseconds(static function () use ($connection): void {
                try {
                    $connection->flush();
                    self::fail('Expected the overflowing batch flush to fail with a NACK');
                } catch (TransportException $exception) {
                    self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                }
            });
        });

        self::assertLessThan(400, $elapsed);
        self::assertSame(0, $this->harness->logger()->countRetryLogs());
        self::assertSame(2, $this->harness->pendingBatchSize($connection));
        self::assertSame(1, $connection->countMessagesInQueues());
    }

    public function testConsumerAckSurvivesPublisherNack(): void
    {
        $name         = $this->harness->topologyName('live_nack_ack');
        $overflowName = $name . '_ov';
        $connection   = $this->harness->connect($this->harness->topology($name, extra: [
            'queues' => [
                $name => ['wait_timeout' => 2],
                $overflowName => [
                    'arguments' => [
                        'x-max-length' => 1,
                        'x-overflow' => 'reject-publish',
                    ],
                ],
            ],
        ]));

        $connection->publish(body: 'to-ack');
        $envelope = $this->harness->consumeOne($connection, $name);

        $this->harness->withRetryDefaults(3, 500, static function () use ($connection): void {
            try {
                $connection->publish(body: 'rejected');
                self::fail('Expected the overflowing publish to fail with a NACK');
            } catch (TransportException $exception) {
                self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
            }
        });

        $envelope->ack();
    }

    public function testNackDropsTheDelivery(): void
    {
        $name       = $this->harness->topologyName('nack_drop');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));

        $connection->publish(body: 'drop-me');
        $envelope = $this->harness->consumeOne($connection, $name);
        self::assertSame('drop-me', $envelope->getBody());

        $envelope->nack();
        $connection->close();

        $replacement = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));
        self::assertSame(0, $replacement->countMessagesInQueues());
    }

    public function testAckAfterPublisherChannelRetirementKeepsTheDeliveryTag(): void
    {
        $name       = $this->harness->topologyName('ack_after_pub_retire');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));

        $connection->setup();
        $connection->publish(body: 'to-ack');
        $envelope        = $this->harness->consumeOne($connection, $name);
        $publisherBefore = $connection->channel();
        $consumerBefore  = $connection->consumerChannel();

        $discardChannel = new ReflectionMethod(Connection::class, 'discardChannel');
        $discardChannel->invoke($connection);

        $envelope->ack();

        self::assertSame($consumerBefore, $connection->consumerChannel());
        self::assertNotSame($publisherBefore, $connection->channel());

        $connection->close();

        $replacement = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));
        self::assertSame(0, $replacement->countMessagesInQueues());
    }

    public function testNackAfterPublisherChannelRetirementDropsTheDelivery(): void
    {
        $name       = $this->harness->topologyName('nack_after_pub_retire');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));

        $connection->setup();
        $connection->publish(body: 'to-nack');
        $envelope        = $this->harness->consumeOne($connection, $name);
        $publisherBefore = $connection->channel();
        $consumerBefore  = $connection->consumerChannel();

        $discardChannel = new ReflectionMethod(Connection::class, 'discardChannel');
        $discardChannel->invoke($connection);

        $envelope->nack();

        self::assertSame($consumerBefore, $connection->consumerChannel());
        self::assertNotSame($publisherBefore, $connection->channel());

        $connection->close();

        $replacement = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));
        self::assertSame(0, $replacement->countMessagesInQueues());
    }

    public function testDecodeFailureNacksTheUndecodableMessage(): void
    {
        $name       = $this->harness->topologyName('decode_fail');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));
        $transport  = new AmqpTransport($connection);

        $connection->publish(body: 'this is not a serialized messenger envelope');

        try {
            /** @var Traversable<mixed, mixed> $envelopes */
            $envelopes = $transport->get();
            iterator_to_array($envelopes, false);
            self::fail('Expected a decode failure for a non-serialized body');
        } catch (MessageDecodingFailedException) {
        } catch (Throwable $exception) {
            if ($exception::class !== 'Symfony\\Component\\Messenger\\Exception\\InvalidMessageSignatureException') {
                throw $exception;
            }
        }

        $connection->close();

        $replacement = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));
        self::assertSame(0, $replacement->countMessagesInQueues());
    }

    public function testPublishFailsWhenAutoSetupIsDisabledUntilSetupIsCalled(): void
    {
        $name       = $this->harness->topologyName('auto_setup_off');
        $connection = $this->harness->connect($this->harness->topology($name, extra: ['auto_setup' => false]));

        $failed = false;
        $this->harness->withRetryDefaults(0, 0, static function () use ($connection, &$failed): void {
            try {
                $connection->publish(body: 'before-setup');
            } catch (Throwable) {
                $failed = true;
            }
        });

        self::assertTrue($failed, 'Expected publish to fail when auto_setup is false and setup() has not run');

        $connection->setup();
        $connection->publish(body: 'after-setup');

        self::assertSame(1, $connection->countMessagesInQueues());
    }

    public function testUnflushedBatchIsNotOnTheBrokerAfterTheConnectionIsAbandoned(): void
    {
        $name       = $this->harness->topologyName('abandon_batch');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);

        self::assertSame(2, $this->harness->pendingBatchSize($connection));
        self::assertSame(0, $connection->countMessagesInQueues());

        $connection->close();

        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $replacement = $this->harness->connect($this->harness->topology($name));

        self::assertSame(0, $replacement->countMessagesInQueues());
        self::assertSame(0, $this->harness->pendingBatchSize($replacement));
    }

    public function testKeepalivePreventsHeartbeatTimeoutDuringLongProcessing(): void
    {
        [$name, $connection] = $this->connectWithHeartbeat('keepalive_ok', ['keepalive_enabled' => true]);
        $transport           = new AmqpTransport($connection);

        $connection->publish(body: 'keep-me');
        $envelope = $this->harness->consumeOne($connection, $name);

        $until = microtime(true) + 4.0;
        while (microtime(true) < $until) {
            $transport->keepalive(new Envelope(new stdClass()));
            usleep(200_000);
        }

        $envelope->ack();

        self::assertTrue($connection->isConnected());
        self::assertSame(0, $connection->countMessagesInQueues());
    }

    public function testKeepaliveIsANoOpWhenDisabledSoLongProcessingMissesHeartbeats(): void
    {
        [$name, $connection] = $this->connectWithHeartbeat('keepalive_off');
        $transport           = new AmqpTransport($connection);

        $connection->publish(body: 'drop-me');
        $envelope = $this->harness->consumeOne($connection, $name);

        $until = microtime(true) + 4.0;
        while (microtime(true) < $until) {
            $transport->keepalive(new Envelope(new stdClass()));
            usleep(200_000);
        }

        $threw = false;

        try {
            $envelope->ack();
            $connection->countMessagesInQueues();
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (Throwable) {
            $threw = true;
        }

        self::assertTrue($threw, 'Expected the broker to close the connection after missed heartbeats');
    }

    public function testDrainConsumerChannelReturnsOnAnIdleLiveConsumer(): void
    {
        $name       = $this->harness->topologyName('drain_idle');
        $connection = $this->harness->connect($this->harness->topology($name, extra: ['confirm_enabled' => false]));
        $connection->setup();
        $connection->startConsumers();

        $started = microtime(true);
        $connection->drainConsumerChannel();
        $elapsed = microtime(true) - $started;

        self::assertLessThan(0.3, $elapsed, 'drainConsumerChannel busy-looped on an idle consumer');
    }

    public function testDrainConsumerChannelReadsEveryReadyDelivery(): void
    {
        $name       = $this->harness->topologyName('drain_all');
        $connection = $this->harness->connect($this->harness->topology(
            $name,
            ['prefetch_count' => 10],
            ['prefetch_count' => 10, 'confirm_enabled' => false],
        ));
        $connection->setup();

        $transport = new AmqpTransport($connection, serializer: new PhpSerializer());
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));
        $this->harness->waitForMessageCount($connection, 3);

        $connection->listen();
        $envelopes = iterator_to_array($transport->get(10), false);

        self::assertCount(3, $envelopes, 'Only one ready AMQP frame was drained per get()');

        foreach ($envelopes as $envelope) {
            $transport->ack($envelope);
        }
    }

    public function testCloseKeepsWaitCoordinatorRegistrationOnALiveConnection(): void
    {
        $name       = $this->harness->topologyName('close_reg');
        $connection = $this->harness->connect($this->harness->topology($name, extra: ['confirm_enabled' => false]));
        $connection->setWaitCoordinator(new ConsumerWaitCoordinator());
        $connection->setup();
        $connection->startConsumers();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());

        $connection->close();

        self::assertTrue(
            $connection->isRegisteredWithWaitCoordinator(),
            'close() dropped the wait-coordinator registration',
        );
    }

    public function testGetPropagatesConnectionFailureOutsideAWorker(): void
    {
        $name       = $this->harness->topologyName('dead_get');
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

        $this->expectException(TransportException::class);

        iterator_to_array($transport->get(), false);
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

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{0: string, 1: Connection}
     */
    private function connectWithHeartbeat(string $label, array $extra = []): array
    {
        $name       = $this->harness->topologyName($label);
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2], [
            'heartbeat' => 1,
            'read_timeout' => 5,
            'write_timeout' => 5,
            'rpc_timeout' => 5,
            ...$extra,
        ]));

        return [$name, $connection];
    }
}
