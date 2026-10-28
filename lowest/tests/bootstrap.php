<?php

declare(strict_types=1);

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestLog;
use Symfony\Component\ErrorHandler\ErrorHandler;

require_once __DIR__ . '/../vendor/autoload.php';

$testLogDir = TestLog::directory();

if (! is_dir($testLogDir)) {
    mkdir($testLogDir, 0777, true);
}

putenv('TEST_LOG_DIR=' . $testLogDir);
$_ENV['TEST_LOG_DIR']    = $testLogDir;
$_SERVER['TEST_LOG_DIR'] = $testLogDir;

$functionalLog = $testLogDir . '/functional.ndjson';
putenv('TEST_FUNCTIONAL_LOG=' . $functionalLog);
$_ENV['TEST_FUNCTIONAL_LOG']    = $functionalLog;
$_SERVER['TEST_FUNCTIONAL_LOG'] = $functionalLog;

/** @var callable(Throwable): void $exceptionHandler */
$exceptionHandler = [new ErrorHandler(), 'handleException'];

set_exception_handler($exceptionHandler);
