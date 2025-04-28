<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Retry;
use RuntimeException;
use Throwable;

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
}
