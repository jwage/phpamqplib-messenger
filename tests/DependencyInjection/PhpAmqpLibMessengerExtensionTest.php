<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\DependencyInjection;

use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
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
    }
}
