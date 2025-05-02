<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use stdClass;

class ConnectionConfigTest extends TestCase
{
    public function testDefaultConstruct(): void
    {
        self::assertDefaultConnectionConfig(new ConnectionConfig());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        self::assertDefaultConnectionConfig(ConnectionConfig::fromArray([]));
    }

    public function testQueuesGetIndexedByQueueName(): void
    {
        $connectionConfig = new ConnectionConfig(
            queues: [
                'queue1' => new QueueConfig(name: 'queue1'),
                'queue2' => new QueueConfig(name: 'queue2'),
            ],
        );

        self::assertSame(['queue1', 'queue2'], $connectionConfig->getQueueNames());
        self::assertEquals(new QueueConfig(name: 'queue1'), $connectionConfig->getQueueConfig('queue1'));
        self::assertEquals(new QueueConfig(name: 'queue2'), $connectionConfig->getQueueConfig('queue2'));
    }

    public function testQueuesKeyMustMatchQueueName(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Queue name "queue_name" does not match array key "queue_name2"');

        new ConnectionConfig(
            queues: ['queue_name2' => new QueueConfig(name: 'queue_name')],
        );
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidOptions(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid connection option(s) "invalid" passed to the AMQP Messenger transport - known options:');

        ConnectionConfig::fromArray(['invalid' => true]);
    }

    public function testFromArray(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'auto_setup' => true,
            'host' => 'example.com',
            'port' => 5673,
            'user' => 'admin',
            'password' => 'admin123',
            'vhost' => '/custom',
            'insist' => false,
            'login_method' => 'PLAIN',
            'locale' => 'fr_FR',
            'connect_timeout' => 5.0,
            'read_timeout' => 4.0,
            'write_timeout' => 4.0,
            'rpc_timeout' => 4.0,
            'heartbeat' => 10,
            'keepalive' => false,
            'prefetch_count' => 15,
            'wait_timeout' => 2.0,
            'confirm_enabled' => true,
            'confirm_timeout' => 10.0,
            'exchange' => [
                'name' => 'custom_exchange',
                'type' => 'fanout',
                'durable' => false,
                'auto_delete' => true,
            ],
            'delay' => [
                'exchange' => [
                    'name' => 'delay_exchange',
                    'type' => 'direct',
                ],
            ],
            'queues' => [
                'queue1' => [
                    'prefetch_count' => 20,
                    'wait_timeout' => 3.0,
                ],
                'queue2' => [
                    'prefetch_count' => 30,
                    'wait_timeout' => 4.0,
                ],
            ],
        ]);

        self::assertTrue($connectionConfig->autoSetup);
        self::assertSame('example.com', $connectionConfig->host);
        self::assertSame(5673, $connectionConfig->port);
        self::assertSame('admin', $connectionConfig->user);
        self::assertSame('admin123', $connectionConfig->password);
        self::assertSame('/custom', $connectionConfig->vhost);
        self::assertFalse($connectionConfig->insist);
        self::assertSame('PLAIN', $connectionConfig->loginMethod);
        self::assertSame('fr_FR', $connectionConfig->locale);
        self::assertSame(5.0, $connectionConfig->connectTimeout);
        self::assertSame(4.0, $connectionConfig->readTimeout);
        self::assertSame(4.0, $connectionConfig->writeTimeout);
        self::assertSame(4.0, $connectionConfig->rpcTimeout);
        self::assertSame(10, $connectionConfig->heartbeat);
        self::assertFalse($connectionConfig->keepalive);
        self::assertSame(15, $connectionConfig->prefetchCount);
        self::assertSame(2.0, $connectionConfig->waitTimeout);
        self::assertTrue($connectionConfig->confirmEnabled);
        self::assertSame(10.0, $connectionConfig->confirmTimeout);

        self::assertEquals(new ExchangeConfig(
            name: 'custom_exchange',
            type: 'fanout',
            durable: false,
            autoDelete: true,
        ), $connectionConfig->exchange);

        self::assertEquals(new DelayConfig(
            exchange: new ExchangeConfig(
                name: 'delay_exchange',
                type: 'direct',
            ),
        ), $connectionConfig->delay);

        self::assertEquals([
            'queue1' => new QueueConfig(
                name: 'queue1',
                prefetchCount: 20,
                waitTimeout: 3.0,
            ),
            'queue2' => new QueueConfig(
                name: 'queue2',
                prefetchCount: 30,
                waitTimeout: 4.0,
            ),
        ], $connectionConfig->queues);

        self::assertSame(['queue1', 'queue2'], $connectionConfig->getQueueNames());
    }

    public function testFromArrayUsesQueueArrayKeyAsQueueName(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'queues' => ['queue_name' => null],
        ]);

        self::assertEquals([
            'queue_name' => new QueueConfig(
                name: 'queue_name',
            ),
        ], $connectionConfig->queues);
    }

    public function testFromArrayWithoutQueueNameAsArrayKey(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'queues' => [
                ['name' => 'queue1'],
                ['name' => 'queue2'],
            ],
        ]);

        self::assertEquals([
            'queue1' => new QueueConfig(
                name: 'queue1',
            ),
            'queue2' => new QueueConfig(
                name: 'queue2',
            ),
        ], $connectionConfig->queues);
    }

    public function testFromArrayWithEmptyQueue(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'queues' => ['queue_name' => null],
        ]);

        self::assertEquals([
            'queue_name' => new QueueConfig(
                name: 'queue_name',
            ),
        ], $connectionConfig->queues);
    }

    public function testFromArrayWithNoQueueName(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Queue name is required');

        ConnectionConfig::fromArray([
            'queues' => [[]],
        ]);
    }

    public function testFromArrayWithInvalidTypes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "object" for key "prefetch_count" (expected integer)');

        ConnectionConfig::fromArray(['prefetch_count' => new stdClass()]);
    }

    public function testGetQueueNames(): void
    {
        $connectionConfig = new ConnectionConfig(queues: [
            'queue1' => new QueueConfig(name: 'queue1'),
            'queue2' => new QueueConfig(name: 'queue2'),
        ]);

        self::assertSame(['queue1', 'queue2'], $connectionConfig->getQueueNames());
    }

    public function testGetQueueConfig(): void
    {
        $queueConfig1 = new QueueConfig(name: 'queue1');
        $queueConfig2 = new QueueConfig(name: 'queue2');

        $connectionConfig = new ConnectionConfig(queues: [
            'queue1' => $queueConfig1,
            'queue2' => $queueConfig2,
        ]);

        self::assertSame($queueConfig1, $connectionConfig->getQueueConfig('queue1'));
        self::assertSame($queueConfig2, $connectionConfig->getQueueConfig('queue2'));
    }

    public function testGetQueueConfigWithInvalidQueueName(): void
    {
        $connectionConfig = new ConnectionConfig();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Queue "invalid-queue-name" not found');

        $connectionConfig->getQueueConfig('invalid-queue-name');
    }

    public function testQueueConfigInheritsFromConnectionConfig(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'prefetch_count' => 10,
            'wait_timeout' => 2,
            'queues' => [
                'queue1' => [],
            ],
        ]);

        self::assertSame(10, $connectionConfig->getQueueConfig('queue1')->prefetchCount);
        self::assertSame(2.0, $connectionConfig->getQueueConfig('queue1')->waitTimeout);
    }

    public function testQueueConfigOverridesConnectionConfig(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'prefetch_count' => 10,
            'wait_timeout' => 2,
            'queues' => [
                'queue1' => [
                    'prefetch_count' => 20,
                    'wait_timeout' => 4,
                ],
            ],
        ]);

        self::assertSame(20, $connectionConfig->getQueueConfig('queue1')->prefetchCount);
        self::assertSame(4.0, $connectionConfig->getQueueConfig('queue1')->waitTimeout);
    }

    #[TestWith([0])]
    #[TestWith([0.0])]
    public function testWaitTimeoutCannotBeZero(int|float $waitTimeout): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Connection wait timeout cannot be zero. This will cause the consumer to wait forever and block the messenger worker loop.');

        new ConnectionConfig(waitTimeout: $waitTimeout);
    }

    public function testTransactionsAndConfirmsCannotBeEnabledAtTheSameTime(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Transactions and confirms cannot be enabled at the same time. You must choose one.');

        new ConnectionConfig(transactionsEnabled: true, confirmEnabled: true);
    }

    public function testGetDelayQueueName(): void
    {
        $connectionConfig = new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'my_queue_name'),
        );

        self::assertSame('delay_my_queue_name_routing_key_1000_retry', $connectionConfig->getDelayQueueName(
            delay: 1000,
            routingKey: 'routing_key',
            isRetryAttempt: true,
        ));

        self::assertSame('delay_my_queue_name_routing_key_1000_delay', $connectionConfig->getDelayQueueName(
            delay: 1000,
            routingKey: 'routing_key',
            isRetryAttempt: false,
        ));

        self::assertSame('delay_my_queue_name__1000_delay', $connectionConfig->getDelayQueueName(
            delay: 1000,
            routingKey: null,
            isRetryAttempt: false,
        ));
    }

    public function testGetHash(): void
    {
        $connectionConfig = new ConnectionConfig();

        self::assertSame('05f85c5ae10ce5a52d553a80cf2ecc17', $connectionConfig->getHash());

        $connectionConfig = ConnectionConfig::fromArray([
            'auto_setup' => true,
            'host' => 'example.com',
            'port' => 5673,
            'user' => 'admin',
            'password' => 'admin123',
            'vhost' => '/custom',
            'insist' => false,
            'login_method' => 'PLAIN',
            'locale' => 'fr_FR',
            'connect_timeout' => 5.0,
            'read_timeout' => 4.0,
            'write_timeout' => 4.0,
            'rpc_timeout' => 4.0,
            'heartbeat' => 10,
            'keepalive' => false,
            'prefetch_count' => 15,
            'wait_timeout' => 2.0,
            'confirm_enabled' => true,
            'confirm_timeout' => 10.0,
            'exchange' => [
                'name' => 'custom_exchange',
                'type' => 'fanout',
                'durable' => false,
                'auto_delete' => true,
            ],
            'delay' => [
                'exchange' => [
                    'name' => 'delay_exchange',
                    'type' => 'direct',
                ],
            ],
            'queues' => [
                'queue1' => [
                    'prefetch_count' => 20,
                    'wait_timeout' => 3.0,
                ],
                'queue2' => [
                    'prefetch_count' => 30,
                    'wait_timeout' => 4.0,
                ],
            ],
        ]);

        self::assertSame('7933824aa9b09330f25625b8fd6e5bb5', $connectionConfig->getHash());
    }

    private static function assertDefaultConnectionConfig(ConnectionConfig $connectionConfig): void
    {
        self::assertTrue($connectionConfig->autoSetup);
        self::assertSame('localhost', $connectionConfig->host);
        self::assertSame(5672, $connectionConfig->port);
        self::assertSame('guest', $connectionConfig->user);
        self::assertSame('guest', $connectionConfig->password);
        self::assertSame('/', $connectionConfig->vhost);
        self::assertFalse($connectionConfig->insist);
        self::assertSame('AMQPLAIN', $connectionConfig->loginMethod);
        self::assertSame('en_US', $connectionConfig->locale);
        self::assertSame(3.0, $connectionConfig->connectTimeout);
        self::assertSame(3.0, $connectionConfig->readTimeout);
        self::assertSame(3.0, $connectionConfig->writeTimeout);
        self::assertSame(3.0, $connectionConfig->rpcTimeout);
        self::assertSame(0, $connectionConfig->heartbeat);
        self::assertTrue($connectionConfig->keepalive);
        self::assertSame(1, $connectionConfig->prefetchCount);
        self::assertSame(1, $connectionConfig->waitTimeout);
        self::assertTrue($connectionConfig->confirmEnabled);
        self::assertSame(3, $connectionConfig->confirmTimeout);
        self::assertEquals(new ExchangeConfig(), $connectionConfig->exchange);
        self::assertEquals(new DelayConfig(), $connectionConfig->delay);
        self::assertSame([], $connectionConfig->queues);
        self::assertEmpty($connectionConfig->getQueueNames());
    }
}
