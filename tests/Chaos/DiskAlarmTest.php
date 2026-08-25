<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

class DiskAlarmTest extends ChaosTestCase
{
    public function testPublishFailsDuringDiskAlarm(): void
    {
        $name       = $this->harness->topologyName('disk');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 10,
            'write_timeout' => 10,
            'rpc_timeout' => 10,
        ]));

        $connection->publish(body: 'before-alarm');
        self::assertSame(1, $connection->countMessagesInQueues());

        $channelBefore = $this->harness->publisherChannel($connection);
        self::assertNotNull($channelBefore);

        $this->harness->info('Forcing a RabbitMQ disk alarm');
        $this->harness->broker('disk-alarm');

        $publishDuringAlarm = function () use ($connection): void {
            try {
                $connection->publish(body: 'during-alarm');
                self::fail('Expected publish to fail while the broker reports a disk alarm');
            } catch (TransportException | AMQPConnectionBlockedException $exception) {
                $this->harness->info('Publish failed during alarm: ' . $exception->getMessage());
            } catch (AssertionFailedError $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $this->harness->info('Publish failed during alarm: ' . $exception::class . ': ' . $exception->getMessage());
            }
        };

        // First attempt drains connection.blocked (often a confirm timeout that keeps the
        // channel). The second must throwIfConnectionBlocked() without discardChannel().
        $publishDuringAlarm();

        // After connection.blocked is visible, throwIfConnectionBlocked() must fail
        // immediately as AMQPConnectionBlockedException — not a confirm timeout, and
        // not a RetryFactory wrap into TransportException.
        $elapsed = (float) $this->harness->withRetryDefaults(0, 0, function () use ($connection): float {
            return $this->harness->milliseconds(function () use ($connection): void {
                try {
                    $connection->publish(body: 'during-alarm-blocked');
                    self::fail('Expected publish to fail while the broker reports a disk alarm');
                } catch (AMQPConnectionBlockedException $exception) {
                    $this->harness->info('Publish blocked: ' . $exception->getMessage());
                }
            });
        });

        self::assertLessThan(500, $elapsed);
        self::assertSame(
            $channelBefore,
            $this->harness->publisherChannel($connection),
            'A known broker alarm must fail before opening or discarding a publisher channel',
        );

        $this->harness->broker('disk-ok');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-alarm');
        });

        self::assertGreaterThanOrEqual(2, $connection->countMessagesInQueues());
    }

    public function testFlushKeepsBatchDuringDiskAlarm(): void
    {
        $name       = $this->harness->topologyName('disk_flush');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 10,
            'write_timeout' => 10,
            'rpc_timeout' => 10,
        ]));

        $connection->channel();
        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $channelBefore = $this->harness->publisherChannel($connection);
        self::assertNotNull($channelBefore);

        $this->harness->info('Forcing a RabbitMQ disk alarm before flush');
        $this->harness->broker('disk-alarm');

        $flushDuringAlarm = function () use ($connection): void {
            $this->harness->withRetryDefaults(0, 0, function () use ($connection): void {
                try {
                    $connection->flush();
                    self::fail('Expected flush to fail while the broker reports a disk alarm');
                } catch (TransportException | AMQPConnectionBlockedException $exception) {
                    $this->harness->info('Flush failed during alarm: ' . $exception->getMessage());
                } catch (AssertionFailedError $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->harness->info('Flush failed during alarm: ' . $exception::class . ': ' . $exception->getMessage());
                }
            });
        };

        // First attempt drains connection.blocked (often a confirm timeout that keeps
        // the channel and batch). The second must throwIfConnectionBlocked() immediately.
        $flushDuringAlarm();

        $elapsed = (float) $this->harness->withRetryDefaults(0, 0, function () use ($connection): float {
            return $this->harness->milliseconds(function () use ($connection): void {
                try {
                    $connection->flush();
                    self::fail('Expected flush to fail while the broker reports a disk alarm');
                } catch (AMQPConnectionBlockedException $exception) {
                    $this->harness->info('Flush blocked: ' . $exception->getMessage());
                }
            });
        });

        self::assertLessThan(500, $elapsed);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));
        self::assertSame(
            $channelBefore,
            $this->harness->publisherChannel($connection),
            'A known broker alarm must fail flush before opening or discarding a publisher channel',
        );

        $this->harness->broker('disk-ok');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->flush();
        });

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertGreaterThanOrEqual(2, $connection->countMessagesInQueues());
    }
}
