<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionIdentity;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistry;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;

final class AmqpConnectionRegistryTest extends TestCase
{
    public function testGetReusesConnectionForSameIdentity(): void
    {
        $config         = new ConnectionConfig();
        $identity       = AmqpConnectionIdentity::fromConnectionConfig($config);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $factory        = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::once())
            ->method('create')
            ->with($config)
            ->willReturn($amqpConnection);

        $registry = new AmqpConnectionRegistry($factory);

        $first  = $registry->get($identity, $config);
        $second = $registry->get($identity, $config);

        self::assertSame($first, $second);
        self::assertSame(0, $registry->generation($identity));
    }

    public function testReconnectReplacesConnectionAndIncrementsGeneration(): void
    {
        $config           = new ConnectionConfig();
        $identity         = AmqpConnectionIdentity::fromConnectionConfig($config);
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
        $registry->get($identity, $config);
        $registry->reconnect($identity, $config);

        self::assertSame(1, $registry->generation($identity));
        self::assertSame($secondConnection, $registry->get($identity, $config));
    }

    public function testDifferentIdentityCreatesDifferentConnection(): void
    {
        $configA     = new ConnectionConfig(connectionName: 'consumer');
        $configB     = new ConnectionConfig(connectionName: 'publisher');
        $identityA   = AmqpConnectionIdentity::fromConnectionConfig($configA);
        $identityB   = AmqpConnectionIdentity::fromConnectionConfig($configB);
        $connectionA = $this->createStub(AMQPStreamConnection::class);
        $connectionB = $this->createStub(AMQPStreamConnection::class);
        $factory     = $this->createMock(AmqpConnectionFactory::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($connectionA, $connectionB);

        $registry = new AmqpConnectionRegistry($factory);

        self::assertNotSame(
            $registry->get($identityA, $configA),
            $registry->get($identityB, $configB),
        );
    }
}
