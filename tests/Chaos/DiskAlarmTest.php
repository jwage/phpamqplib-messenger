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
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'before-alarm');
        self::assertSame(1, $connection->countMessagesInQueues());

        $this->harness->info('Forcing a RabbitMQ disk alarm');
        $this->harness->broker('disk-alarm');

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
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->channel();
        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->info('Forcing a RabbitMQ disk alarm before flush');
        $this->harness->broker('disk-alarm');

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

        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->broker('disk-ok');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->flush();
        });

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertGreaterThanOrEqual(2, $connection->countMessagesInQueues());
    }
}
