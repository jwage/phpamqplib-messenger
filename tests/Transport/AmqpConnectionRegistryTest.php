<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionIdentity;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistry;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistryKey;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionReuse;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRole;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;

final class AmqpConnectionRegistryTest extends TestCase
{
    public function testGetReusesConnectionForSameRegistryKey(): void
    {
        $config  = new ConnectionConfig();
        $key     = $this->registryKey($config, AmqpConnectionReuse::ALL, AmqpConnectionRole::MIXED);
        $stream  = $this->createStub(AMQPStreamConnection::class);
        $factory = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::once())
            ->method('create')
            ->with($config)
            ->willReturn($stream);

        $registry = new AmqpConnectionRegistry($factory);

        $first  = $registry->get($key, $config);
        $second = $registry->get($key, $config);

        self::assertSame($first, $second);
        self::assertSame(0, $registry->generation($key));
    }

    public function testReconnectReplacesConnectionAndIncrementsGeneration(): void
    {
        $config           = new ConnectionConfig();
        $key              = $this->registryKey($config, AmqpConnectionReuse::ALL, AmqpConnectionRole::MIXED);
        $firstConnection  = $this->createMock(AMQPStreamConnection::class);
        $secondConnection = $this->createStub(AMQPStreamConnection::class);
        $factory          = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->with($config)
            ->willReturnOnConsecutiveCalls($firstConnection, $secondConnection);

        $firstConnection->expects(self::once())
            ->method('close');

        $registry = new AmqpConnectionRegistry($factory);
        $registry->get($key, $config);
        $registry->reconnect($key, $config);

        self::assertSame(1, $registry->generation($key));
        self::assertSame($secondConnection, $registry->get($key, $config));
    }

    public function testDifferentBrokerIdentityCreatesDifferentConnection(): void
    {
        $configA     = new ConnectionConfig(connectionName: 'consumer');
        $configB     = new ConnectionConfig(connectionName: 'publisher');
        $keyA        = $this->registryKey($configA, AmqpConnectionReuse::ALL, AmqpConnectionRole::MIXED);
        $keyB        = $this->registryKey($configB, AmqpConnectionReuse::ALL, AmqpConnectionRole::MIXED);
        $connectionA = $this->createStub(AMQPStreamConnection::class);
        $connectionB = $this->createStub(AMQPStreamConnection::class);
        $factory     = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($connectionA, $connectionB);

        $registry = new AmqpConnectionRegistry($factory);

        self::assertNotSame(
            $registry->get($keyA, $configA),
            $registry->get($keyB, $configB),
        );
    }

    public function testNoneModeWithDifferentDedicatedIdsDoesNotReuse(): void
    {
        $config  = new ConnectionConfig();
        $keyA    = $this->registryKey($config, AmqpConnectionReuse::NONE, AmqpConnectionRole::MIXED, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $keyB    = $this->registryKey($config, AmqpConnectionReuse::NONE, AmqpConnectionRole::MIXED, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $streamA = $this->createStub(AMQPStreamConnection::class);
        $streamB = $this->createStub(AMQPStreamConnection::class);
        $factory = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($streamA, $streamB);

        $registry = new AmqpConnectionRegistry($factory);

        self::assertNotSame($registry->get($keyA, $config), $registry->get($keyB, $config));
    }

    public function testProducerConsumerSharesAmongSameRole(): void
    {
        $config  = new ConnectionConfig();
        $key1    = $this->registryKey($config, AmqpConnectionReuse::PRODUCER_CONSUMER, AmqpConnectionRole::PRODUCER);
        $key2    = $this->registryKey($config, AmqpConnectionReuse::PRODUCER_CONSUMER, AmqpConnectionRole::PRODUCER);
        $stream  = $this->createStub(AMQPStreamConnection::class);
        $factory = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::once())
            ->method('create')
            ->willReturn($stream);

        $registry = new AmqpConnectionRegistry($factory);

        self::assertSame($registry->get($key1, $config), $registry->get($key2, $config));
    }

    public function testProducerConsumerDoesNotShareProducerWithConsumer(): void
    {
        $config  = new ConnectionConfig();
        $keyProd = $this->registryKey($config, AmqpConnectionReuse::PRODUCER_CONSUMER, AmqpConnectionRole::PRODUCER);
        $keyCons = $this->registryKey($config, AmqpConnectionReuse::PRODUCER_CONSUMER, AmqpConnectionRole::CONSUMER);
        $streamA = $this->createStub(AMQPStreamConnection::class);
        $streamB = $this->createStub(AMQPStreamConnection::class);
        $factory = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($streamA, $streamB);

        $registry = new AmqpConnectionRegistry($factory);

        self::assertNotSame($registry->get($keyProd, $config), $registry->get($keyCons, $config));
    }

    public function testAllSharesAcrossProducerAndConsumerRoles(): void
    {
        $config  = new ConnectionConfig();
        $keyProd = $this->registryKey($config, AmqpConnectionReuse::ALL, AmqpConnectionRole::PRODUCER);
        $keyCons = $this->registryKey($config, AmqpConnectionReuse::ALL, AmqpConnectionRole::CONSUMER);
        $stream  = $this->createStub(AMQPStreamConnection::class);
        $factory = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::once())
            ->method('create')
            ->willReturn($stream);

        $registry = new AmqpConnectionRegistry($factory);

        self::assertSame($registry->get($keyProd, $config), $registry->get($keyCons, $config));
    }

    public function testReconnectOnlyAffectsMatchingRegistryKey(): void
    {
        $config   = new ConnectionConfig();
        $keyA     = $this->registryKey($config, AmqpConnectionReuse::PRODUCER_CONSUMER, AmqpConnectionRole::PRODUCER);
        $keyB     = $this->registryKey($config, AmqpConnectionReuse::PRODUCER_CONSUMER, AmqpConnectionRole::CONSUMER);
        $streamA1 = $this->createMock(AMQPStreamConnection::class);
        $streamB  = $this->createStub(AMQPStreamConnection::class);
        $streamA2 = $this->createStub(AMQPStreamConnection::class);
        $factory  = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::exactly(3))
            ->method('create')
            ->willReturnOnConsecutiveCalls($streamA1, $streamB, $streamA2);

        $streamA1->expects(self::once())
            ->method('close');

        $registry = new AmqpConnectionRegistry($factory);
        $registry->get($keyA, $config);
        $registry->get($keyB, $config);
        $registry->reconnect($keyA, $config);

        self::assertSame(1, $registry->generation($keyA));
        self::assertSame(0, $registry->generation($keyB));
        self::assertSame($streamB, $registry->get($keyB, $config));
    }

    private function registryKey(
        ConnectionConfig $config,
        AmqpConnectionReuse $reuse,
        AmqpConnectionRole $role,
        string $dedicatedInstanceId = '',
    ): AmqpConnectionRegistryKey {
        return AmqpConnectionRegistryKey::create(
            AmqpConnectionIdentity::fromConnectionConfig($config),
            $reuse,
            $role,
            $dedicatedInstanceId,
        );
    }
}
