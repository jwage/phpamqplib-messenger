<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use JsonException;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function get_debug_type;
use function getenv;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_scalar;
use function is_string;
use function json_encode;
use function microtime;
use function mkdir;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/**
 * Writes diagnostic NDJSON for live, e2e, functional, and chaos tests.
 *
 * PHPUnit is strict about test stdout, so traces go to files under TEST_LOG_DIR
 * (tests/_output locally, a workspace path in CI).
 */
final class TestLog
{
    private static string $currentTest = '';

    public static function directory(): string
    {
        $dir = getenv('TEST_LOG_DIR');

        if (is_string($dir) && $dir !== '') {
            return $dir;
        }

        return __DIR__ . '/_output';
    }

    public static function beginTest(string $name): void
    {
        self::$currentTest = $name;
        self::event('test.start', ['test' => $name]);
    }

    public static function endTest(string|null $name = null): void
    {
        self::event('test.end', ['test' => $name ?? self::$currentTest]);
        self::$currentTest = '';
    }

    public static function currentTest(): string
    {
        return self::$currentTest;
    }

    /** @param array<array-key, mixed> $payload */
    public static function event(string $event, array $payload = []): void
    {
        $record = [
            't' => microtime(true),
            'test' => self::$currentTest,
            'event' => $event,
            ...self::redactArray($payload),
        ];

        self::append('events.ndjson', self::encode($record));
    }

    public static function copyFile(string $source, string $basename): void
    {
        if ($source === '' || ! is_file($source)) {
            return;
        }

        $contents = file_get_contents($source);

        if ($contents === false || $contents === '') {
            return;
        }

        self::write($basename, self::redact($contents));
    }

    public static function write(string $basename, string $contents): void
    {
        $dir = self::ensureDirectory();

        if ($dir === null) {
            return;
        }

        @file_put_contents(
            $dir . '/' . self::safeBasename($basename),
            $contents,
            LOCK_EX,
        );
    }

    public static function dumpLogger(CollectingLogger $logger, string $testName): void
    {
        if ($logger->records === []) {
            return;
        }

        $lines = [];

        foreach ($logger->records as $record) {
            $lines[] = self::encode([
                't' => $record['t'],
                'test' => $testName,
                'event' => 'psr-log',
                'level' => $record['level'],
                'message' => $record['message'],
                'template' => $record['template'],
                'context' => $record['context'],
            ]);
        }

        self::write(self::safeBasename($testName) . '.logger.ndjson', implode("\n", $lines) . "\n");
    }

    public static function redact(string $value): string
    {
        $redacted = preg_replace('~(phpamqplibs?://[^:/?#]+:)[^@/]+@~', '$1***@', $value);

        return $redacted ?? $value;
    }

    public static function redactDsn(string $dsn): string
    {
        return self::redact($dsn);
    }

    public static function safeBasename(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?? 'log';

        if ($safe === '') {
            return 'log';
        }

        return $safe;
    }

    /**
     * Keep broker / consume output in the artifact without dumping megabytes.
     */
    public static function excerpt(string $output, int $maxChars = 4000): string
    {
        $output = self::redact($output);

        if ($output === '') {
            return '';
        }

        if (strlen($output) <= $maxChars) {
            return $output;
        }

        return substr($output, 0, $maxChars) . sprintf("\n... [%d more bytes]", strlen($output) - $maxChars);
    }

    private static function ensureDirectory(): string|null
    {
        $dir = self::directory();

        if (is_dir($dir)) {
            return $dir;
        }

        if (! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return null;
        }

        return $dir;
    }

    private static function append(string $basename, string $line): void
    {
        $dir = self::ensureDirectory();

        if ($dir === null || $line === '') {
            return;
        }

        @file_put_contents($dir . '/' . $basename, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<array-key, mixed> $record */
    private static function encode(array $record): string
    {
        try {
            return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return '';
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function redactArray(array $value): array
    {
        $redacted = [];

        /** @psalm-suppress MixedAssignment */
        foreach ($value as $key => $item) {
            $redacted[$key] = self::redactValue($item);
        }

        return $redacted;
    }

    private static function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }

        if (is_array($value)) {
            return self::redactArray($value);
        }

        if ($value instanceof Throwable) {
            return $value::class . ': ' . self::redact($value->getMessage());
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return get_debug_type($value);
    }
}
