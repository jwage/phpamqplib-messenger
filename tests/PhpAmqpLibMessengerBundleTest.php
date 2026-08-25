<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerCompilerPass;
use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PhpAmqpLibMessengerBundleTest extends TestCase
{
    public function testBuildRegistersTheCompilerPass(): void
    {
        $container = new ContainerBuilder();
        $bundle    = new PhpAmqpLibMessengerBundle();

        $bundle->build($container);

        $found = false;

        foreach ($container->getCompilerPassConfig()->getPasses() as $pass) {
            if ($pass instanceof PhpAmqpLibMessengerCompilerPass) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found);
    }

    public function testGetContainerExtensionReturnsTheSameInstance(): void
    {
        $bundle = new PhpAmqpLibMessengerBundle();

        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(PhpAmqpLibMessengerExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
