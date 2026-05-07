<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionIdentity;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistryKey;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionReuse;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRole;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;

final class AmqpConnectionRegistryKeyTest extends TestCase
{
    public function testNoneRequiresDedicatedInstanceId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AmqpConnectionRegistryKey::create(
            AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig()),
            AmqpConnectionReuse::NONE,
            AmqpConnectionRole::MIXED,
            '',
        );
    }

    public function testAllRejectsDedicatedInstanceId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AmqpConnectionRegistryKey::create(
            AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig()),
            AmqpConnectionReuse::ALL,
            AmqpConnectionRole::MIXED,
            'notempty',
        );
    }
}
