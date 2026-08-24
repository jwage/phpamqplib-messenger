#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\BrokerRestartBeforeFlush;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\BrokerRestartDuringFlush;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\ConfirmTimeoutRewait;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\MemoryAlarm;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\NackOverflow;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\RetainedBatchBeforeDirect;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\Smoke;

require_once __DIR__ . '/../../vendor/autoload.php';

const SCENARIOS = [
    'smoke' => Smoke::class,
    'nack-overflow' => NackOverflow::class,
    'confirm-timeout-rewait' => ConfirmTimeoutRewait::class,
    'broker-restart-before-flush' => BrokerRestartBeforeFlush::class,
    'broker-restart-during-flush' => BrokerRestartDuringFlush::class,
    'memory-alarm' => MemoryAlarm::class,
    'retained-batch-before-direct' => RetainedBatchBeforeDirect::class,
];

$requested = array_values(array_filter($argv, static fn (string $arg): bool => $arg !== $argv[0] && ! str_starts_with($arg, '-')));
$flags     = array_values(array_filter($argv, static fn (string $arg): bool => str_starts_with($arg, '-')));

if (in_array('--help', $flags, true) || in_array('-h', $flags, true) || in_array('help', $requested, true)) {
    usage();
    exit(0);
}

if (in_array('--list', $flags, true) || in_array('list', $requested, true)) {
    listScenarios();
    exit(0);
}

$names = $requested === [] ? array_keys(SCENARIOS) : $requested;

foreach ($names as $name) {
    if (! isset(SCENARIOS[$name])) {
        fwrite(STDERR, sprintf("Unknown scenario '%s'. Try --list.\n", $name));
        exit(1);
    }
}

$failed = [];

foreach ($names as $name) {
    fwrite(STDOUT, sprintf("\n==> %s\n%s\n", $name, (string) SCENARIOS[$name]::DESCRIPTION));

    $harness = new Harness();

    try {
        $harness->waitUntilReady();
        SCENARIOS[$name]::run($harness);
        fwrite(STDOUT, sprintf("PASS %s\n", $name));
    } catch (Throwable $exception) {
        $failed[] = $name;
        fwrite(STDERR, sprintf("FAIL %s: %s\n", $name, $exception->getMessage()));
    } finally {
        $harness->cleanup();
    }
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("\n%d scenario(s) failed: %s\n", count($failed), implode(', ', $failed)));
    exit(1);
}

fwrite(STDOUT, sprintf("\n%d scenario(s) passed.\n", count($names)));
exit(0);

function usage(): void
{
    fwrite(STDOUT, <<<'EOF'
Live RabbitMQ failure scenarios (not PHPUnit).

These scripts start from a real broker, inject a failure (restart, pause,
overflow NACK, memory alarm), then check that phpamqplib-messenger still
honours at-least-once publish behaviour.

Usage:
  php tests/bin/chaos.php
  php tests/bin/chaos.php --list
  php tests/bin/chaos.php nack-overflow confirm-timeout-rewait

  tests/bin/chaos-broker up
  php tests/bin/chaos.php smoke
  tests/bin/chaos-broker down

Environment:
  MESSENGER_TRANSPORT_PHPAMQPLIB_DSN  defaults to phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages

Do not run these in parallel with PHPUnit: they pause/restart the shared broker.

EOF);
}

function listScenarios(): void
{
    foreach (SCENARIOS as $name => $class) {
        fwrite(STDOUT, sprintf("%-32s %s\n", $name, $class::DESCRIPTION));
    }
}
