<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PHPUnit\Framework\TestCase;

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

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidQueueConfig(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid option(s) "invalid" passed to the AMQP Messenger transport.');

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
            'connection_timeout' => 5.0,
            'read_timeout' => 4.0,
            'write_timeout' => 4.0,
            'channel_rpc_timeout' => 4.0,
            'heartbeat' => 10,
            'keepalive' => false,
            'prefetch_count' => 15,
            'wait_timeout' => 2.0,
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
                    'prefetch_count' => 15,
                    'wait_timeout' => 2.0,
                ],
                'queue2' => [
                    'prefetch_count' => 15,
                    'wait_timeout' => 2.0,
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
        self::assertSame(5.0, $connectionConfig->connectionTimeout);
        self::assertSame(4.0, $connectionConfig->readTimeout);
        self::assertSame(4.0, $connectionConfig->writeTimeout);
        self::assertSame(4.0, $connectionConfig->channelRPCTimeout);
        self::assertSame(10, $connectionConfig->heartbeat);
        self::assertFalse($connectionConfig->keepalive);
        self::assertSame(15, $connectionConfig->prefetchCount);
        self::assertSame(2.0, $connectionConfig->waitTimeout);
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
        self::assertSame(['queue1', 'queue2'], $connectionConfig->getQueueNames());
    }

    public function testGetQueueNames(): void
    {
        $connectionConfig = new ConnectionConfig(queues: [
            'queue1' => new QueueConfig(),
            'queue2' => new QueueConfig(),
        ]);

        self::assertSame(['queue1', 'queue2'], $connectionConfig->getQueueNames());
    }

    public function testGetQueueConfig(): void
    {
        $queueConfig1 = new QueueConfig();
        $queueConfig2 = new QueueConfig();

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
        self::assertSame(3.0, $connectionConfig->connectionTimeout);
        self::assertSame(3.0, $connectionConfig->readTimeout);
        self::assertSame(3.0, $connectionConfig->writeTimeout);
        self::assertSame(3.0, $connectionConfig->channelRPCTimeout);
        self::assertSame(0, $connectionConfig->heartbeat);
        self::assertTrue($connectionConfig->keepalive);
        self::assertSame(5, $connectionConfig->prefetchCount);
        self::assertSame(1, $connectionConfig->waitTimeout);
        self::assertEquals(new ExchangeConfig(), $connectionConfig->exchange);
        self::assertEquals(new DelayConfig(), $connectionConfig->delay);
        self::assertSame([], $connectionConfig->queues);
        self::assertEmpty($connectionConfig->getQueueNames());
    }
}
