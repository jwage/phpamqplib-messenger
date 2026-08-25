<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Psr\Log\AbstractLogger;
use Stringable;

use function str_contains;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /**
     * Compatible with psr/log 1–3 (lowest Symfony 6.4 still uses untyped log()).
     *
     * @param mixed                   $level
     * @param string|Stringable       $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
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
