<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Compat;

use Jwage\PhpAmqpLibMessengerBundle\Compat\BundleBase;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use ReflectionClass;

class BundleBaseTest extends TestCase
{
    public function testIsAbstract(): void
    {
        self::assertTrue(new ReflectionClass(BundleBase::class)->isAbstract());
    }

    public function testConcreteSubclassIsABundleBase(): void
    {
        $bundle = new class extends BundleBase {
        };

        self::assertInstanceOf(BundleBase::class, $bundle);
    }
}
