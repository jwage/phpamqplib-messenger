<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function hrtime;
use function max;
use function min;

class RetryTest extends TestCase
{
    public function testRetry(): void
    {
        $count = 0;

        $return = (new Retry(
            waitTime: 0,
        ))
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

    public function testBeforeRetry(): void
    {
        $retries = 0;
        $runs    = 0;

        $return = (new Retry(
            waitTime: 0,
        ))
            ->beforeRetry(static function () use (&$retries): void {
                $retries++;
            })
            ->run(static function () use (&$runs): string {
                $runs++;

                if ($runs < 3) {
                    throw new InvalidArgumentException();
                }

                return 'foo';
            });

        self::assertSame(2, $retries);
        self::assertSame(3, $runs);
        self::assertSame('foo', $return);
    }

    public function testCatch(): void
    {
        $retries = 0;
        $runs    = 0;

        try {
            (new Retry(
                waitTime: 0,
            ))
                ->catch(Throwable::class)
                ->except(InvalidArgumentException::class)
                ->beforeRetry(static function () use (&$retries): void {
                    $retries++;
                })
                ->run(static function () use (&$runs): string {
                    $runs++;

                    if ($runs < 3) {
                        throw new RuntimeException('This should get retried');
                    }

                    if ($runs === 3) {
                        throw new InvalidArgumentException('This should not get retried');
                    }

                    return 'foo';
                });
        } catch (Throwable $e) {
            self::assertInstanceOf(InvalidArgumentException::class, $e);
            self::assertSame('This should not get retried', $e->getMessage());
        }

        self::assertSame(2, $retries);
        self::assertSame(3, $runs);
    }

    public function testEmptyCatchListDoesNotRetry(): void
    {
        $runs = 0;

        try {
            (new Retry(
                retries: 3,
                waitTime: 0,
            ))
                ->catch([])
                ->run(static function () use (&$runs): void {
                    $runs++;

                    throw new RuntimeException('not retried');
                });

            self::fail('Expected the exception to propagate without retries.');
        } catch (RuntimeException $exception) {
            self::assertSame('not retried', $exception->getMessage());
        }

        self::assertSame(1, $runs);
    }

    public function testNullConstructorArgumentsFallBackToDefaults(): void
    {
        $runs = 0;

        try {
            (new RecordingRetry(
                waitTime: 0,
                jitter: false,
            ))
                ->catch(RuntimeException::class)
                ->run(static function () use (&$runs): void {
                    $runs++;

                    throw new RuntimeException('again');
                });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException) {
        }

        self::assertSame(Retry::$defaultRetries + 1, $runs);
    }

    public function testExhaustedRetriesWrapTheExceptionAsTransportException(): void
    {
        $runs = 0;

        try {
            (new Retry(
                retries: 0,
                waitTime: 0,
            ))
                ->catch(InvalidArgumentException::class)
                ->run(static function () use (&$runs): void {
                    $runs++;

                    throw new InvalidArgumentException('gave up');
                });

            self::fail('Expected exhausted retries to wrap the exception.');
        } catch (TransportException $exception) {
            self::assertSame('gave up', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }

        self::assertSame(1, $runs);
    }

    public function testRetryBudgetIsDecrementedNotIncremented(): void
    {
        $runs = 0;

        try {
            (new RecordingRetry(
                retries: 1,
                waitTime: 0,
                jitter: false,
            ))
                ->catch(RuntimeException::class)
                ->run(static function () use (&$runs): void {
                    $runs++;

                    throw new RuntimeException('again');
                });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException $exception) {
            self::assertSame('again', $exception->getMessage());
        }

        self::assertSame(2, $runs);
    }

    public function testWaitTimeIsConvertedToMicrosecondsWithoutJitter(): void
    {
        $retry = (new RecordingRetry(
            retries: 1,
            waitTime: 50,
            jitter: false,
        ))->catch(RuntimeException::class);

        try {
            $retry->run(static function (): void {
                throw new RuntimeException('again');
            });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException) {
        }

        self::assertSame([50000], $retry->sleeps);
    }

    public function testJitterRandomizesTheWaitBelowTheConfiguredMaximum(): void
    {
        $retry = (new RecordingRetry(
            retries: 20,
            waitTime: 50,
            jitter: true,
        ))->catch(RuntimeException::class);

        try {
            $retry->run(static function (): void {
                throw new RuntimeException('again');
            });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException) {
        }

        self::assertCount(20, $retry->sleeps);
        self::assertLessThan(50000, min(...$retry->sleeps));
        self::assertLessThanOrEqual(50000, max(...$retry->sleeps));
    }

    public function testDisabledJitterIsNotReplacedByTheDefault(): void
    {
        $retry = (new RecordingRetry(
            retries: 3,
            waitTime: 50,
            jitter: false,
        ))->catch(RuntimeException::class);

        try {
            $retry->run(static function (): void {
                throw new RuntimeException('again');
            });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException) {
        }

        self::assertSame([50000, 50000, 50000], $retry->sleeps);
    }

    public function testLoggerReceivesRetryContext(): void
    {
        $logger    = $this->createMock(LoggerInterface::class);
        $exception = new RuntimeException('transient');

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Retrying {message}',
                self::callback(static function (array $context) use ($exception): bool {
                    self::assertSame('transient', $context['message']);
                    self::assertSame(1, $context['retries']);
                    self::assertSame($exception, $context['exception']);
                    self::assertArrayHasKey('callable', $context);

                    return true;
                }),
            );

        try {
            (new RecordingRetry(
                retries: 1,
                waitTime: 0,
                jitter: false,
            ))
                ->catch(RuntimeException::class)
                ->setLogger($logger)
                ->run(static function () use ($exception): void {
                    throw $exception;
                });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException) {
        }
    }

    public function testSleepRemainsOverridable(): void
    {
        $method = new ReflectionMethod(Retry::class, 'sleep');

        self::assertTrue($method->isProtected());
        self::assertFalse($method->isPrivate());

        // Invoke the method body so PCOV covers the sleep line; this ensures
        // Infection includes this test when checking ProtectedVisibility mutations.
        $method->invoke(new Retry(), 0);
    }

    public function testRetryWaitsUsingUsleepBetweenAttempts(): void
    {
        $start = hrtime(true);

        try {
            (new Retry(
                retries: 1,
                waitTime: 30,
                jitter: false,
            ))
                ->catch(RuntimeException::class)
                ->run(static function (): void {
                    throw new RuntimeException('again');
                });

            self::fail('Expected retries to be exhausted.');
        } catch (TransportException) {
        }

        self::assertGreaterThanOrEqual(20.0, (hrtime(true) - $start) / 1_000_000);
    }
}
