<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Exception;
use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
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
