<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\BindingConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use PhpAmqpLib\Connection\AMQPConnectionConfig;

use function http_build_query;

class DsnParserTest extends TestCase
{
    private DsnParser $dsnParser;

    public function testParseEmptyDsn(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://');

        self::assertSame(true, $connectionConfig->autoSetup);
        self::assertSame('localhost', $connectionConfig->host);
        self::assertSame(5672, $connectionConfig->port);
        self::assertSame('guest', $connectionConfig->user);
        self::assertSame('guest', $connectionConfig->password);
        self::assertSame('/', $connectionConfig->vhost);
        self::assertSame(false, $connectionConfig->insist);
        self::assertSame(AMQPConnectionConfig::AUTH_AMQPPLAIN, $connectionConfig->loginMethod);
        self::assertSame('en_US', $connectionConfig->locale);
        self::assertSame(3.0, $connectionConfig->connectTimeout);
        self::assertSame(3.0, $connectionConfig->readTimeout);
        self::assertSame(3.0, $connectionConfig->writeTimeout);
        self::assertSame(3.0, $connectionConfig->rpcTimeout);
        self::assertSame(0, $connectionConfig->heartbeat);
        self::assertSame(true, $connectionConfig->keepalive);
        self::assertNull($connectionConfig->ssl);
        self::assertEquals(new ExchangeConfig(name: 'messages'), $connectionConfig->exchange);
        self::assertEquals(new DelayConfig(), $connectionConfig->delay);
        self::assertEquals(['messages' => new QueueConfig(name: 'messages')], $connectionConfig->queues);
        self::assertSame('', $connectionConfig->connectionName);
    }

    public function testMissingSslConfig(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('No ssl configuration has been provided. Alternatively, you can use phpamqplib:// to use without SSL.');

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

    public function testParseDsnWithBooleanAutoSetup(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://guest:guest@127.0.0.1:5672/vhost?auto_setup=true');

        self::assertSame(true, $connectionConfig->autoSetup);
    }

    public function testParseDsnWithInvalidAutoSetupValue(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "string" for key "auto_setup" (expected boolean)');

        $this->dsnParser->parseDsn('phpamqplib://guest:guest@127.0.0.1:5672/vhost?auto_setup=not_a_bool');
    }

    public function testUsernameAndPassword(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://username:password@127.0.0.1');

        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1?login=username&password=password');

        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1?user=username&password=password');

        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1', [
            'user' => 'username',
            'password' => 'password',
        ]);

        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1', [
            'login' => 'username',
            'password' => 'password',
        ]);

        self::assertSame('username', $connectionConfig->user);
        self::assertSame('password', $connectionConfig->password);
    }

    public function testConnectionName(): void
    {
        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1');

        self::assertSame('', $connectionConfig->connectionName);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1?connection_name=MyConnection');

        self::assertSame('MyConnection', $connectionConfig->connectionName);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1', ['connection_name' => 'MyConnection']);

        self::assertSame('MyConnection', $connectionConfig->connectionName);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1?connection_name=My+Connection');

        self::assertSame('My Connection', $connectionConfig->connectionName);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1?connection_name=My%2FConnection');

        self::assertSame('My/Connection', $connectionConfig->connectionName);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1', ['connection_name' => 'My Connection']);

        self::assertSame('My Connection', $connectionConfig->connectionName);

        $connectionConfig = $this->dsnParser->parseDsn('phpamqplib://127.0.0.1', ['connection_name' => 'My/Connection']);

        self::assertSame('My/Connection', $connectionConfig->connectionName);
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
        self::assertSame(true, $connectionConfig->insist);
        self::assertSame('login_method', $connectionConfig->loginMethod);
        self::assertSame('locale', $connectionConfig->locale);
        self::assertSame(1.0, $connectionConfig->connectTimeout);
        self::assertSame(2.0, $connectionConfig->readTimeout);
        self::assertSame(3.0, $connectionConfig->writeTimeout);
        self::assertSame(4.0, $connectionConfig->rpcTimeout);
        self::assertSame(5, $connectionConfig->heartbeat);
        self::assertSame(true, $connectionConfig->keepalive);
        self::assertSame('connection_name', $connectionConfig->connectionName);

        self::assertEquals(new SslConfig(
            cafile: 'cacert',
        ), $connectionConfig->ssl);

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
                name: 'queue1',
                passive: true,
                durable: true,
                exclusive: true,
                autoDelete: true,
                bindings: [
                    'routing_key' => BindingConfig::fromArray([
                        'routing_key' => 'routing_key',
                        'arguments' => ['key' => 'value'],
                    ]),
                ],
                arguments: ['key' => 'value'],
            ),
            'queue2' => new QueueConfig(
                name: 'queue2',
                passive: true,
                durable: true,
                exclusive: true,
                autoDelete: true,
                bindings: [
                    'routing_key' => BindingConfig::fromArray([
                        'routing_key' => 'routing_key',
                        'arguments' => ['key' => 'value'],
                    ]),
                ],
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
            'insist' => true,
            'login_method' => 'login_method',
            'locale' => 'locale',
            'connect_timeout' => 1.0,
            'read_timeout' => 2.0,
            'write_timeout' => 3.0,
            'rpc_timeout' => 4.0,
            'heartbeat' => 5,
            'keepalive' => true,
            'ssl' => ['cafile' => 'cacert'],
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
                    'bindings' => ['routing_key' => ['arguments' => ['key' => 'value']]],
                    'arguments' => ['key' => 'value'],
                ],
                'queue2' => [
                    'passive' => true,
                    'durable' => true,
                    'exclusive' => true,
                    'auto_delete' => true,
                    'bindings' => ['routing_key' => ['arguments' => ['key' => 'value']]],
                ],
            ],
            'connection_name' => 'connection_name',
        ];
    }
}
