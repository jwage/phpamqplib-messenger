#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);

$phpunit      = $root . '/vendor/bin/phpunit';
$args         = array_slice($_SERVER['argv'] ?? [], 1);
$passthruArgs = ['--testsuite', 'chaos'];
$filters      = [];

foreach ($args as $arg) {
    if (in_array($arg, ['--help', '-h', 'help'], true)) {
        fwrite(STDOUT, <<<'EOF'
Live RabbitMQ failure tests (PHPUnit --testsuite chaos).

These tests start from a real broker, inject a failure (restart, pause,
overflow NACK, memory alarm), then check that phpamqplib-messenger still
honours at-least-once publish behaviour.

They are excluded from the default PHPUnit suite so `./vendor/bin/phpunit`
does not pause or restart the broker used by functional tests.

Usage:
  php tests/bin/chaos.php
  php tests/bin/chaos.php --list
  php tests/bin/chaos.php NackTest ConfirmTimeoutTest

  ./vendor/bin/phpunit --testsuite chaos
  ./vendor/bin/phpunit --testsuite chaos --filter NackTest

  tests/bin/chaos-broker up
  php tests/bin/chaos.php SmokeTest
  tests/bin/chaos-broker down

Environment:
  MESSENGER_TRANSPORT_PHPAMQPLIB_DSN      defaults to phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages
  MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN  defaults to phpamqplibs://guest:guest@127.0.0.1:5671/%2f/messages
  CHAOS_VERBOSE=1                         print broker commands and test logs

Do not run these in parallel with the default PHPUnit suite: they pause/restart the shared broker.

EOF);
        exit(0);
    }

    if (in_array($arg, ['--list', 'list'], true)) {
        $passthruArgs[] = '--list-tests';
        continue;
    }

    if (str_starts_with($arg, '-')) {
        $passthruArgs[] = $arg;
        continue;
    }

    $filters[] = $arg;
}

if ($filters !== []) {
    $passthruArgs[] = '--filter';
    $passthruArgs[] = implode('|', $filters);
}

$command = sprintf(
    '%s %s',
    escapeshellarg($phpunit),
    implode(' ', array_map(static fn (string $arg): string => escapeshellarg($arg), $passthruArgs)),
);

passthru($command, $exitCode);
exit($exitCode);
