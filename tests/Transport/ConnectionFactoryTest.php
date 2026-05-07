<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistry;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use ReflectionClass;

use function array_keys;

class ConnectionFactoryTest extends TestCase
{
    private DsnParser $dsnParser;

    private RetryFactory $retryFactory;

    private AmqpConnectionFactory $amqpConnectionFactory;

    private AmqpConnectionRegistry $amqpConnectionRegistry;

    private ConnectionFactory $connectionFactory;

    public function testFromDsn(): void
    {
        $connection = $this->connectionFactory->fromDsn('phpamqplibs://guest:guest@localhost:5672?ssl[cafile]=/path/to/cacert.pem', [
            'exchange' => ['name' => 'exchange_name'],
            'queues' => ['queue_name' => []],
        ]);

        self::assertFalse($connection->isConnected());

        $connectionConfig = $connection->getConfig();

        self::assertSame('guest', $connectionConfig->user);
        self::assertSame('guest', $connectionConfig->password);
        self::assertSame('localhost', $connectionConfig->host);
        self::assertSame(5672, $connectionConfig->port);
        self::assertSame('/', $connectionConfig->vhost);
        self::assertInstanceOf(SslConfig::class, $connectionConfig->ssl);
        self::assertEquals('/path/to/cacert.pem', $connectionConfig->ssl->cafile);
    }

    public function testConnectionReuseNoneProducesDistinctRegistryKeysPerFromDsn(): void
    {
        $stream = $this->createStub(AMQPStreamConnection::class);
        $stream->method('isConnected')->willReturn(false);
        $amqpFactory = $this->createStub(AmqpConnectionFactory::class);
        $amqpFactory->method('create')->willReturn($stream);
        $registry = new AmqpConnectionRegistry($amqpFactory);

        $factory = new ConnectionFactory(
            $this->dsnParser,
            $this->retryFactory,
            $registry,
            'none',
        );

        $dsn = 'phpamqplib://guest:guest@localhost/%2f/messages';
        $factory->fromDsn($dsn)->isConnected();
        $factory->fromDsn($dsn)->isConnected();

        $keys = $this->registryEntryKeyStrings($registry);
        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    public function testConnectionReuseAllProducesSameRegistryKeyForSameBroker(): void
    {
        $stream = $this->createStub(AMQPStreamConnection::class);
        $stream->method('isConnected')->willReturn(false);
        $amqpFactory = $this->createStub(AmqpConnectionFactory::class);
        $amqpFactory->method('create')->willReturn($stream);
        $registry = new AmqpConnectionRegistry($amqpFactory);

        $factory = new ConnectionFactory(
            $this->dsnParser,
            $this->retryFactory,
            $registry,
            'all',
        );

        $dsn = 'phpamqplib://guest:guest@localhost/%2f/messages';
        $factory->fromDsn($dsn)->isConnected();
        $factory->fromDsn($dsn)->isConnected();

        $keys = $this->registryEntryKeyStrings($registry);
        self::assertCount(1, $keys);
    }

    public function testConnectionReuseProducerConsumerSeparatesRoles(): void
    {
        $stream = $this->createStub(AMQPStreamConnection::class);
        $stream->method('isConnected')->willReturn(false);
        $amqpFactory = $this->createStub(AmqpConnectionFactory::class);
        $amqpFactory->method('create')->willReturn($stream);
        $registry = new AmqpConnectionRegistry($amqpFactory);

        $factory = new ConnectionFactory(
            $this->dsnParser,
            $this->retryFactory,
            $registry,
            'producer-consumer',
        );

        $dsn = 'phpamqplib://guest:guest@localhost/%2f/messages';
        $factory->fromDsn($dsn, ['connection_role' => 'producer'])->isConnected();
        $factory->fromDsn($dsn, ['connection_role' => 'consumer'])->isConnected();

        $keys = $this->registryEntryKeyStrings($registry);
        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    /** @return list<string> */
    private function registryEntryKeyStrings(AmqpConnectionRegistry $registry): array
    {
        $property = (new ReflectionClass(AmqpConnectionRegistry::class))->getProperty('entries');
        $property->setAccessible(true);
        /** @var array<string, mixed> $entries */
        $entries = $property->getValue($registry);

        return array_keys($entries);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dsnParser              = new DsnParser();
        $this->retryFactory           = new RetryFactory();
        $this->amqpConnectionFactory  = new AmqpConnectionFactory();
        $this->amqpConnectionRegistry = new AmqpConnectionRegistry($this->amqpConnectionFactory);

        $this->connectionFactory = new ConnectionFactory(
            $this->dsnParser,
            $this->retryFactory,
            $this->amqpConnectionRegistry,
            'none',
        );
    }
}
