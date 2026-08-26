<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use RuntimeException;

use function dirname;
use function file_get_contents;
use function function_exists;
use function getenv;
use function is_file;
use function is_resource;
use function microtime;
use function posix_kill;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const SIGKILL;
use const SIGTERM;

final class E2eConsumeProcess
{
    /** @var resource|null */
    private $process = null;

    private string $stdoutFile = '';

    private string $stderrFile = '';

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    public function start(array $arguments, array $env): void
    {
        $stdoutFile = tempnam(sys_get_temp_dir(), 'e2e-out-');
        $stderrFile = tempnam(sys_get_temp_dir(), 'e2e-err-');

        $this->stdoutFile = $stdoutFile === false ? sys_get_temp_dir() . '/e2e-out' : $stdoutFile;
        $this->stderrFile = $stderrFile === false ? sys_get_temp_dir() . '/e2e-err' : $stderrFile;

        $command = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/console',
            ...$arguments,
        ];

        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->stdoutFile, 'w'],
                2 => ['file', $this->stderrFile, 'w'],
            ],
            $pipes,
            cwd: dirname(__DIR__, 2),
            env_vars: $this->mergeEnv($env),
            options: ['create_new_session' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start messenger:consume');
        }

        $this->process = $process;
    }

    public function waitUntilOutputContains(string $needle, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (str_contains($this->stdout() . $this->stderr(), $needle)) {
                return;
            }

            $status = $this->status();

            if ($status !== null && $status['running'] === false) {
                throw new RuntimeException(sprintf(
                    'messenger:consume exited before becoming ready (exit %s). Output:%s',
                    (string) $status['exitcode'],
                    $this->debugOutput(),
                ));
            }

            usleep(20_000);
        }

        throw new RuntimeException(sprintf(
            'Timed out waiting for %s in messenger:consume output.%s',
            $needle,
            $this->debugOutput(),
        ));
    }

    public function wait(float $timeout): int
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $status = $this->status();

            if ($status !== null && $status['running'] === false) {
                $exitCode = $status['exitcode'] ?? 1;

                $this->close();

                return $exitCode === false ? 1 : $exitCode;
            }

            usleep(20_000);
        }

        $this->stop();

        throw new RuntimeException('messenger:consume did not exit in time.' . $this->debugOutput());
    }

    public function signal(int $signal): void
    {
        $status = $this->status();
        $pid    = $status['pid'] ?? 0;

        if ($status === null || $status['running'] !== true || $pid <= 0 || ! function_exists('posix_kill')) {
            return;
        }

        posix_kill($pid, $signal);
    }

    public function stop(): void
    {
        $status = $this->status();
        $pid    = $status['pid'] ?? 0;

        if ($status !== null && $status['running'] === true && $pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
            usleep(100_000);

            $status = $this->status();

            if ($status !== null && $status['running'] === true) {
                posix_kill($pid, SIGKILL);
            }
        }

        $this->close();
    }

    public function stdout(): string
    {
        if (! is_file($this->stdoutFile)) {
            return '';
        }

        $contents = file_get_contents($this->stdoutFile);

        return $contents === false ? '' : $contents;
    }

    public function stderr(): string
    {
        if (! is_file($this->stderrFile)) {
            return '';
        }

        $contents = file_get_contents($this->stderrFile);

        return $contents === false ? '' : $contents;
    }

    public function debugOutput(): string
    {
        return "\n--- stdout ---\n" . $this->stdout() . "\n--- stderr ---\n" . $this->stderr();
    }

    /** @return array{running: bool, pid: int, exitcode: int|false}|null */
    public function status(): array|null
    {
        if (! is_resource($this->process)) {
            return null;
        }

        /** @var array{running: bool, pid: int, exitcode: int|false} $status */
        $status = proc_get_status($this->process);

        return $status;
    }

    public function cleanupFiles(): void
    {
        foreach ([$this->stdoutFile, $this->stderrFile] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
    }

    private function close(): void
    {
        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->process = null;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    private function mergeEnv(array $env): array
    {
        return $env + getenv();
    }
}
