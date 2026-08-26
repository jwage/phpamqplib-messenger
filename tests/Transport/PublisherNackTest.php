<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use RuntimeException;

class PublisherNackTest extends TestCase
{
    public function testIsARuntimeAmqpException(): void
    {
        $exception = new PublisherNack('The broker negatively acknowledged a published message.');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertInstanceOf(AMQPExceptionInterface::class, $exception);
        self::assertSame('The broker negatively acknowledged a published message.', $exception->getMessage());
    }
}
