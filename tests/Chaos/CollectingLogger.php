<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestLog;
use LogicException;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function get_debug_type;
use function is_array;
use function is_scalar;
use function is_string;
use function microtime;
use function str_contains;
use function strtr;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{t: float, level: string, message: string, template: string, context: array<array-key, mixed>}> */
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
        $template     = (string) $message;
        $safeContext  = $this->jsonContext($context);
        $interpolated = TestLog::redact($this->interpolate($template, $safeContext));

        $this->records[] = [
            't' => microtime(true),
            'level' => (string) $level,
            'message' => $interpolated,
            'template' => $template,
            'context' => $safeContext,
        ];

        TestLog::event('psr-log', [
            'level' => (string) $level,
            'message' => $interpolated,
            'template' => $template,
            'context' => $safeContext,
        ]);
    }

    public function countRetryLogs(): int
    {
        $count = 0;

        foreach ($this->records as $record) {
            if (str_contains($record['message'], 'Retrying') || str_contains($record['template'], 'Retrying')) {
                $count++;
            }
        }

        return $count;
    }

    public function hasTemplate(string $template): bool
    {
        foreach ($this->records as $record) {
            if ($record['template'] === $template) {
                return true;
            }
        }

        return false;
    }

    /** @return array<array-key, mixed> */
    public function contextFor(string $template): array
    {
        foreach ($this->records as $record) {
            if ($record['template'] === $template) {
                return $record['context'];
            }
        }

        throw new LogicException('No log record with template: ' . $template);
    }

    /** @return list<string> */
    public function templates(): array
    {
        $templates = [];

        foreach ($this->records as $record) {
            $templates[] = $record['template'];
        }

        return $templates;
    }

    /** @param array<array-key, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        /** @psalm-suppress MixedAssignment */
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function jsonContext(array $context): array
    {
        $safe = [];

        /** @psalm-suppress MixedAssignment */
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $safe[$key] = $value::class . ': ' . TestLog::redact($value->getMessage());

                continue;
            }

            if (is_string($value)) {
                $safe[$key] = TestLog::redact($value);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->jsonContext($value);

                continue;
            }

            $safe[$key] = get_debug_type($value);
        }

        return $safe;
    }
}
