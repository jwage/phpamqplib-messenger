<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Exception;
use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();
    }
}
