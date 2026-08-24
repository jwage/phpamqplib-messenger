<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('chaos')]
abstract class ChaosTestCase extends TestCase
{
    protected Harness $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new Harness();
        $this->harness->waitUntilReady();
    }

    protected function tearDown(): void
    {
        $this->harness->cleanup();

        parent::tearDown();
    }
}
