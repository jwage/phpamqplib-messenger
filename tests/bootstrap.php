<?php

declare(strict_types=1);

use Symfony\Component\ErrorHandler\ErrorHandler;

require_once __DIR__ . '/../vendor/autoload.php';

/** @var callable(Throwable): void $exceptionHandler */
$exceptionHandler = [new ErrorHandler(), 'handleException'];

set_exception_handler($exceptionHandler);
