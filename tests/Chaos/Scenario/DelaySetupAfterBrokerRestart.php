<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;

final class DelaySetupAfterBrokerRestart
{
    public const string DESCRIPTION = 'Delayed publish recreates delay topology after the broker restarts.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('delay_restart');
        $connection = $harness->connect($harness->topology($name, extra: [
            'delay' => [
                'enabled' => true,
                'auto_setup' => true,
                'exchange' => ['name' => $name . '_delays'],
                'queue_name_pattern' => $name . '_%delay%',
            ],
        ]));

        $connection->setup();

        $harness->info('Restarting broker before a delayed publish');
        $harness->broker('restart');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'delayed', delayInMs: 300);
        });

        $harness->waitForMessageCount($connection, 1, 10);
        $harness->info('Delayed publish recovered delay topology after broker restart');
    }
}
