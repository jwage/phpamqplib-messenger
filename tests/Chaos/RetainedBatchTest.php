<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Throwable;

class RetainedBatchTest extends ChaosTestCase
{
    public function testFailedBatchFlushesBeforeLaterDirectPublish(): void
    {
        $name       = $this->harness->topologyName('retained');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->info('Stopping broker so the first flush fails');
        $this->harness->broker('stop');

        $this->harness->withRetryDefaults(0, 0, function () use ($connection): void {
            try {
                $connection->flush();
                self::fail('Expected flush to fail while the broker is stopped');
            } catch (Throwable $exception) {
                $this->harness->info('Flush failed as expected: ' . $exception->getMessage());
            }
        });

        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->broker('start');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'direct-three', batchSize: 1);
        });

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertSame(3, $connection->countMessagesInQueues());
    }

    public function testAutoFlushAfterFailedFlushAtThreshold(): void
    {
        $name       = $this->harness->topologyName('auto_flush');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'one', batchSize: 2);
        self::assertSame(1, $this->harness->pendingBatchSize($connection));

        $this->harness->info('Stopping broker so auto-flush fails at the batch threshold');
        $this->harness->broker('stop');

        $this->harness->withRetryDefaults(0, 0, function () use ($connection): void {
            try {
                $connection->publish(body: 'two', batchSize: 2);
                self::fail('Expected auto-flush to fail while the broker is stopped');
            } catch (Throwable $exception) {
                $this->harness->info('Auto-flush failed as expected: ' . $exception->getMessage());
            }
        });

        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->broker('start');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'three', batchSize: 2);
        });

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertSame(3, $connection->countMessagesInQueues());
    }
}
