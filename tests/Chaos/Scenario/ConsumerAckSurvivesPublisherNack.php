<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function sprintf;

final class ConsumerAckSurvivesPublisherNack
{
    public const string DESCRIPTION = 'A publisher NACK does not invalidate an outstanding consumer delivery tag.';

    public static function run(Harness $harness): void
    {
        $name         = $harness->topologyName('nack_ack');
        $overflowName = $name . '_ov';
        $connection   = $harness->connect($harness->topology($name, extra: [
            'queues' => [
                $name => ['wait_timeout' => 2],
                $overflowName => [
                    'arguments' => [
                        'x-max-length' => 1,
                        'x-overflow' => 'reject-publish',
                    ],
                ],
            ],
        ]));

        $connection->publish(body: 'to-ack');
        $envelope = $harness->consumeOne($connection, $name);

        $elapsed = (float) $harness->withRetryDefaults(3, 500, static function () use ($harness, $connection): float {
            return $harness->milliseconds(static function () use ($harness, $connection): void {
                try {
                    $connection->publish(body: 'rejected');
                    $harness->fail('Expected the overflowing publish to fail with a NACK');
                } catch (TransportException $exception) {
                    $harness->assertInstanceOf(
                        PublisherNack::class,
                        $exception->getPrevious(),
                        'the transport exception should wrap PublisherNack',
                    );
                } catch (Throwable $exception) {
                    $harness->fail('Unexpected exception: ' . $exception::class . ': ' . $exception->getMessage());
                }
            });
        });

        $harness->assertTrue(
            $elapsed < 400,
            sprintf('NACK should fail immediately without retry backoff, took %.1fms', $elapsed),
        );

        $envelope->ack();
        $harness->info('Consumer ack remained valid after the publisher NACK');
    }
}
