<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use PhpAmqpLib\Connection\AMQPConnectionConfig;

use function http_build_query;

class DsnParserTest extends TestCase
{
    private DsnParser $dsnParser;

    public function testParseEmptyDsn(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://');

        $this->assertSame(true, $connectionConfig->autoSetup);
        $this->assertSame('localhost', $connectionConfig->host);
        $this->assertSame(5672, $connectionConfig->port);
        $this->assertSame('guest', $connectionConfig->user);
        $this->assertSame('guest', $connectionConfig->password);
        $this->assertSame('/', $connectionConfig->vhost);
        $this->assertSame(null, $connectionConfig->cacert);
        $this->assertSame(false, $connectionConfig->insist);
        $this->assertSame(AMQPConnectionConfig::AUTH_AMQPPLAIN, $connectionConfig->loginMethod);
        $this->assertSame('en_US', $connectionConfig->locale);
        $this->assertSame(3.0, $connectionConfig->connectionTimeout);
        $this->assertSame(3.0, $connectionConfig->readTimeout);
        $this->assertSame(3.0, $connectionConfig->writeTimeout);
        $this->assertSame(3.0, $connectionConfig->channelRPCTimeout);
        $this->assertSame(0, $connectionConfig->heartbeat);
        $this->assertSame(true, $connectionConfig->keepalive);
        $this->assertEquals(new ExchangeConfig(name: 'messages'), $connectionConfig->exchange);
        $this->assertEquals(new DelayConfig(), $connectionConfig->delay);
        $this->assertEquals(['messages' => new QueueConfig()], $connectionConfig->queues);
    }

    public function testMissingCaCert(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('No CA certificate has been provided. Pass the "cacert" parameter in the DSN to use SSL. Alternatively, you can use phpamqplib:// to use without SSL.');

        $this->dsnParser->parseDsn('phpamqplibs://');
    }

    public function testParseFullDsn(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://username:password@127.0.0.1:1234/vhost');

        self::assertSame('127.0.0.1', $connectionConfig->host);
        self::assertSame(1234, $connectionConfig->port);
        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);
        self::assertSame('vhost', $connectionConfig->vhost);
    }

    public function testQueryParams(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplibs://127.0.0.1?' . http_build_query(self::getConnectionConfig()));

        self::assertConnectionConfig($connectionConfig);
    }

    public function testParseDsnWithOptions(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplibs://', self::getConnectionConfig());

        self::assertConnectionConfig($connectionConfig);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dsnParser = new DsnParser();
    }

    private static function assertConnectionConfig(ConnectionConfig $connectionConfig): void
    {
        self::assertSame('127.0.0.1', $connectionConfig->host);
        self::assertSame(1234, $connectionConfig->port);
        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);
        self::assertSame('vhost', $connectionConfig->vhost);
        self::assertSame('cacert', $connectionConfig->cacert);
        self::assertSame(true, $connectionConfig->insist);
        self::assertSame('login_method', $connectionConfig->loginMethod);
        self::assertSame('locale', $connectionConfig->locale);
        self::assertSame(1.0, $connectionConfig->connectionTimeout);
        self::assertSame(2.0, $connectionConfig->readTimeout);
        self::assertSame(3.0, $connectionConfig->writeTimeout);
        self::assertSame(4.0, $connectionConfig->channelRPCTimeout);
        self::assertSame(5, $connectionConfig->heartbeat);
        self::assertSame(true, $connectionConfig->keepalive);

        self::assertEquals(new ExchangeConfig(
            name: 'exchange_name',
            type: 'exchange_type',
            defaultPublishRoutingKey: 'default_publish_routing_key',
            passive: true,
            durable: true,
            autoDelete: true,
            arguments: ['key' => 'value'],
        ), $connectionConfig->exchange);

        self::assertEquals(new DelayConfig(
            exchange: new ExchangeConfig(
                name: 'exchange_name',
                type: 'exchange_type',
                defaultPublishRoutingKey: 'default_publish_routing_key',
                passive: true,
                durable: true,
                autoDelete: true,
            ),
            queueNamePattern: 'queue_name_pattern',
            arguments: ['key' => 'value'],
        ), $connectionConfig->delay);

        self::assertEquals([
            'queue1' => new QueueConfig(
                passive: true,
                durable: true,
                exclusive: true,
                autoDelete: true,
                bindingKeys: ['binding_key'],
                bindingArguments: ['key' => 'value'],
                arguments: ['key' => 'value'],
            ),
            'queue2' => new QueueConfig(
                passive: true,
                durable: true,
                exclusive: true,
                autoDelete: true,
                bindingKeys: ['binding_key'],
            ),
        ], $connectionConfig->queues);
    }

    /** @return array<string, mixed> */
    private static function getConnectionConfig(): array
    {
        return [
            'host' => '127.0.0.1',
            'user' => 'username',
            'password' => 'password',
            'port' => 1234,
            'vhost' => 'vhost',
            'cacert' => 'cacert',
            'insist' => true,
            'login_method' => 'login_method',
            'locale' => 'locale',
            'connection_timeout' => 1.0,
            'read_timeout' => 2.0,
            'write_timeout' => 3.0,
            'channel_rpc_timeout' => 4.0,
            'heartbeat' => 5,
            'keepalive' => true,
            'exchange' => [
                'name' => 'exchange_name',
                'type' => 'exchange_type',
                'default_publish_routing_key' => 'default_publish_routing_key',
                'passive' => true,
                'durable' => true,
                'auto_delete' => true,
                'arguments' => ['key' => 'value'],
            ],
            'delay' => [
                'exchange' => [
                    'name' => 'exchange_name',
                    'type' => 'exchange_type',
                    'default_publish_routing_key' => 'default_publish_routing_key',
                    'passive' => true,
                    'durable' => true,
                    'auto_delete' => true,
                ],
                'queue_name_pattern' => 'queue_name_pattern',
                'arguments' => ['key' => 'value'],
            ],
            'queues' => [
                'queue1' => [
                    'passive' => true,
                    'durable' => true,
                    'exclusive' => true,
                    'auto_delete' => true,
                    'binding_keys' => ['binding_key'],
                    'binding_arguments' => ['key' => 'value'],
                    'arguments' => ['key' => 'value'],
                ],
                'queue2' => [
                    'passive' => true,
                    'durable' => true,
                    'exclusive' => true,
                    'auto_delete' => true,
                    'binding_keys' => ['binding_key'],
                ],
            ],
        ];
    }
}
