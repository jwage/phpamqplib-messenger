<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

use function str_contains;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
        ];
    }

    public function countRetryLogs(): int
    {
        $count = 0;

        foreach ($this->records as $record) {
            if (str_contains($record['message'], 'Retrying')) {
                $count++;
            }
        }

        return $count;
    }
}
