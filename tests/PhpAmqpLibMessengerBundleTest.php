<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle;

class PhpAmqpLibMessengerBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsTheSameInstance(): void
    {
        $bundle = new PhpAmqpLibMessengerBundle();

        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(PhpAmqpLibMessengerExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
