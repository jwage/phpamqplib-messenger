<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function file_put_contents;
use function get_debug_type;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function microtime;
use function strtr;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/**
 * PSR-3 logger that appends NDJSON to a file for e2e/functional consume processes.
 */
final class FileLogger extends AbstractLogger
{
    public function __construct(
        private string $file,
    ) {
    }

    /**
     * Compatible with psr/log 1–3 (lowest Symfony 6.4 still uses untyped log()).
     *
     * @param mixed                   $level
     * @param string|Stringable       $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        if ($this->file === '') {
            return;
        }

        $template     = (string) $message;
        $safeContext  = $this->jsonContext($context);
        $interpolated = TestLog::redact($this->interpolate($template, $safeContext));

        $encoded = json_encode([
            't' => microtime(true),
            'level' => (string) $level,
            'message' => $interpolated,
            'template' => $template,
            'context' => $safeContext,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        file_put_contents($this->file, $encoded . "\n", FILE_APPEND | LOCK_EX);
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
