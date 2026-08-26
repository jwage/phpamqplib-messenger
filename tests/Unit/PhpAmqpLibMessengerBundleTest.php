<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit;

use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;

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
