<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Exception;
use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

class RetryFactoryTest extends TestCase
{
    private RetryFactory $retryFactory;

    public function testRetry(): void
    {
        $count = 0;

        $return = $this->retryFactory->retry(
            waitTime: 0,
        )
            ->catch(InvalidArgumentException::class)
            ->run(static function () use (&$count): string {
                $count++;

                if ($count < 3) {
                    throw new InvalidArgumentException();
                }

                return 'foo';
            });

        self::assertSame(3, $count);
        self::assertSame('foo', $return);
    }

    public function testWillNotRetryThrowable(): void
    {
        self::expectException(Throwable::class);
        self::expectExceptionMessage('Did not retry');

        $this->retryFactory->retry()
            ->run(static function (): void {
                throw new Exception('Did not retry');
            });
    }

    public function testWillNotRetryPublisherNack(): void
    {
        $count = 0;

        try {
            $this->retryFactory->retry(waitTime: 0)
                ->run(static function () use (&$count): void {
                    $count++;

                    throw new PublisherNack('The broker negatively acknowledged a published message.');
                });

            self::fail('Expected PublisherNack to propagate without retry.');
        } catch (PublisherNack $exception) {
            self::assertSame('The broker negatively acknowledged a published message.', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    public function testWillOnlyRetryCertainExceptions(): void
    {
        $count = 0;

        $return = $this->retryFactory->retry(
            waitTime: 0,
        )
            ->run(static function () use (&$count): string {
                $count++;

                if ($count < 3) {
                    throw new AMQPConnectionClosedException();
                }

                return 'foo';
            });

        self::assertSame(3, $count);
        self::assertSame('foo', $return);
    }

    /** @param class-string<Throwable> $exceptionClass */
    #[TestWith([AMQPChannelClosedException::class])]
    #[TestWith([AMQPConnectionClosedException::class])]
    #[TestWith([AMQPIOException::class])]
    #[TestWith([AMQPTimeoutException::class])]
    public function testRetriesRetryableAmqpExceptions(string $exceptionClass): void
    {
        $count = 0;

        $return = $this->retryFactory->retry(waitTime: 0)
            ->run(static function () use (&$count, $exceptionClass): string {
                $count++;

                if ($count < 3) {
                    throw new $exceptionClass('retry me');
                }

                return 'foo';
            });

        self::assertSame(3, $count);
        self::assertSame('foo', $return);
    }

    public function testWillNotRetryAMQPConnectionBlockedException(): void
    {
        $count = 0;

        try {
            $this->retryFactory->retry(waitTime: 0)
                ->run(static function () use (&$count): void {
                    $count++;

                    throw new AMQPConnectionBlockedException('Connection blocked');
                });

            self::fail('Expected AMQPConnectionBlockedException to propagate without retry.');
        } catch (AMQPConnectionBlockedException $exception) {
            self::assertSame('Connection blocked', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    public function testWillNotRetryTransportException(): void
    {
        $count = 0;

        try {
            $this->retryFactory->retry(waitTime: 0)
                ->run(static function () use (&$count): void {
                    $count++;

                    throw new TransportException('already wrapped');
                });

            self::fail('Expected TransportException to propagate without retry.');
        } catch (TransportException $exception) {
            self::assertSame('already wrapped', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    public function testWillNotRetryAMQPProtocolChannelException(): void
    {
        $count = 0;

        try {
            $this->retryFactory->retry(waitTime: 0)
                ->run(static function () use (&$count): void {
                    $count++;

                    throw new AMQPProtocolChannelException(1, 'PRECONDITION_FAILED', []);
                });

            self::fail('Expected AMQPProtocolChannelException to propagate without retry.');
        } catch (AMQPProtocolChannelException $exception) {
            self::assertSame('PRECONDITION_FAILED', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();
    }
}
