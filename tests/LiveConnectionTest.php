<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

use function preg_replace;
use function sleep;
use function str_contains;
use function strtolower;

#[Group('live')]
class LiveConnectionTest extends TestCase
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
        } catch (Throwable $exception) {
            $haystack = strtolower($exception->getMessage() . ' ' . ($exception->getPrevious()?->getMessage() ?? ''));
            self::assertTrue(
                str_contains($haystack, 'refused')
                || str_contains($haystack, 'access')
                || str_contains($haystack, 'auth'),
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
}
