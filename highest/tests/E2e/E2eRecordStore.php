<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_file;
use function json_encode;
use function microtime;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

class E2eRecordStore
{
    public function __construct(
        private string $logFile,
    ) {
    }

    /** @param array<string, mixed> $extra */
    public function record(string $type, string $id, array $extra = []): void
    {
        file_put_contents(
            $this->logFile,
            json_encode([
                't' => microtime(true),
                'type' => $type,
                'id' => $id,
                'pid' => getmypid(),
                ...$extra,
            ], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    public function incrementAttempts(string $id): int
    {
        $file     = $this->logFile . '.' . $id . '.attempts';
        $contents = is_file($file) ? file_get_contents($file) : false;
        $attempts = (int) ($contents === false ? '0' : $contents) + 1;

        file_put_contents($file, (string) $attempts);

        return $attempts;
    }
}
