<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\DependencyInjection;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PhpAmqpLibMessengerExtensionTest extends TestCase
{
    public function testLoadRegistersTheTransportFactory(): void
    {
        $container = new ContainerBuilder();
        $extension = new PhpAmqpLibMessengerExtension();

        $extension->load([], $container);

        self::assertTrue($container->hasDefinition(AmqpTransportFactory::class));
        self::assertTrue($container->getDefinition(AmqpTransportFactory::class)->hasTag('messenger.transport_factory'));
        self::assertTrue($container->hasDefinition(Debug::class));
        self::assertCount(2, $container->getDefinition(Debug::class)->getArguments());
        self::assertCount(1, $container->getDefinition(ConsumerWaitCoordinator::class)->getArguments());
        self::assertCount(2, $container->getDefinition(AmqpWorkerListener::class)->getArguments());
    }
}
