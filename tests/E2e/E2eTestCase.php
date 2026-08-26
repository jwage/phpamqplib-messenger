<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestLog;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function getmypid;
use function glob;
use function implode;
use function is_array;
use function is_file;
use function json_decode;
use function microtime;
use function preg_match;
use function preg_quote;
use function putenv;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

/**
 * @psalm-type E2eRecord = array{
 *     t: float,
 *     type: string,
 *     id: string,
 *     failed: bool,
 *     will_retry: bool,
 *     pid: int,
 *     transport: string,
 *     queue: string|null,
 *     message_id: string|null
 * }
 */
abstract class E2eTestCase extends TestCase
{
    protected KernelInterface $kernel;

    protected string $logFile = '';

    protected string $debugLogFile = '';

    protected string $highDsn;

    protected string $lowDsn;

    protected string $orderQueue;

    protected string $quoteQueue;

    /** @var list<E2eConsumeProcess> */
    private array $processes = [];

    /** @var array<string, string> */
    private array $env = [];

    private bool $booted = false;

    private string $consumeHelp = '';

    private bool $consumeHelpLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        $pid    = getmypid();
        $prefix = sprintf('e2e_%s_%s', $pid === false ? '0' : (string) $pid, uniqid());

        $this->logFile      = sys_get_temp_dir() . '/' . $prefix . '.log';
        $this->debugLogFile = sys_get_temp_dir() . '/' . $prefix . '.debug.ndjson';
        $this->highDsn      = $this->dsnForExchange($prefix . '_high');
        $this->lowDsn       = $this->dsnForExchange($prefix . '_low');
        $this->orderQueue   = $prefix . '_order';
        $this->quoteQueue   = $prefix . '_quote';

        file_put_contents($this->logFile, '');
        file_put_contents($this->debugLogFile, '');

        $testLogDir = TestLog::directory();

        $this->env = [
            'APP_ENV' => 'test',
            // kernel.debug so wait/consume traces land in E2E_DEBUG_LOG / TEST_LOG_DIR.
            'APP_DEBUG' => '1',
            'E2E_CACHE' => 'e2e-v7',
            'E2E_LOG' => $this->logFile,
            'E2E_DEBUG_LOG' => $this->debugLogFile,
            'TEST_LOG_DIR' => $testLogDir,
            'E2E_HIGH_DSN' => $this->highDsn,
            'E2E_LOW_DSN' => $this->lowDsn,
            'E2E_FAILED_DSN' => $this->dsnForExchange($prefix . '_failed'),
            'E2E_TX_DSN' => $this->dsnForExchange($prefix . '_tx'),
            'E2E_MULTI_DSN' => $this->dsnForExchange($prefix . '_multi'),
            'E2E_MULTI_EXCHANGE' => $prefix . '_multi',
            'E2E_ORDER_QUEUE' => $this->orderQueue,
            'E2E_QUOTE_QUEUE' => $this->quoteQueue,
            'E2E_GREEDY_DSN' => $this->dsnForExchange($prefix . '_greedy'),
            'E2E_MANUAL_DSN' => $this->dsnForExchange($prefix . '_manual'),
            'E2E_KEEPALIVE_DSN' => $this->dsnForExchange($prefix . '_keepalive'),
            'E2E_SSL_DSN' => $this->sslDsnForPrefix($prefix),
            'E2E_AUTO_DSN' => $this->dsnForExchange($prefix . '_auto'),
            'E2E_NO_ACK_DSN' => $this->dsnForExchange($prefix . '_no_ack'),
            'SHELL_VERBOSITY' => '3',
        ];

        $this->applyEnv($this->env);

        $this->kernel = new E2eKernel('test', true);
        $this->kernel->boot();

        foreach (
            [
                'e2e_high',
                'e2e_low',
                'e2e_failed',
                'e2e_tx',
                'e2e_multi',
                'e2e_greedy',
                'e2e_keepalive',
            ] as $transport
        ) {
            $this->setupTransport($transport);
        }

        $this->clearMessengerRestartSignal();
        $this->booted = true;
    }

    protected function tearDown(): void
    {
        $this->archiveTestLogs();

        foreach ($this->processes as $process) {
            $process->stop();
            $process->cleanupFiles();
        }

        $this->processes = [];

        if ($this->booted) {
            $this->clearMessengerRestartSignal();

            foreach (
                [
                    'e2e_high',
                    'e2e_low',
                    'e2e_failed',
                    'e2e_tx',
                    'e2e_multi',
                    'e2e_greedy',
                    'e2e_manual',
                    'e2e_keepalive',
                    'e2e_ssl',
                    'e2e_auto',
                    'e2e_no_ack',
                ] as $transport
            ) {
                $this->deleteTransportTopology($transport);
            }

            $this->kernel->shutdown();

            $attemptFiles = glob($this->logFile . '.*.attempts');

            if (is_array($attemptFiles)) {
                foreach ($attemptFiles as $file) {
                    unlink($file);
                }
            }

            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }

            if (is_file($this->debugLogFile)) {
                unlink($this->debugLogFile);
            }
        }

        $this->booted = false;

        parent::tearDown();
    }

    /**
     * @param list<string> $receivers
     * @param list<string> $extra
     */
    protected function startConsume(
        array $receivers,
        int|null $limit = null,
        float|null $sleep = null,
        array $extra = [],
        int $timeLimit = 20,
    ): E2eConsumeProcess {
        $arguments = ['messenger:consume', ...$receivers, '--no-interaction', '-vvv', '--time-limit=' . $timeLimit];

        if ($limit !== null) {
            $arguments[] = '--limit=' . $limit;
        }

        if ($sleep !== null) {
            $arguments[] = sprintf('--sleep=%s', $sleep);
        }

        foreach ($extra as $argument) {
            $arguments[] = $argument;
        }

        $process = new E2eConsumeProcess();
        $process->start($arguments, $this->env);
        $this->processes[] = $process;

        return $process;
    }

    protected function setConsumeEnv(string $name, string $value): void
    {
        $this->env[$name] = $value;
    }

    /** @param list<string> $arguments */
    protected function runConsole(array $arguments, float $timeout = 15.0): int
    {
        $process = new E2eConsumeProcess();
        $process->start(['--no-interaction', '-vv', ...$arguments], $this->env);
        $this->processes[] = $process;

        return $process->wait($timeout);
    }

    protected function waitUntilConsuming(E2eConsumeProcess|null $process = null): void
    {
        $process ??= $this->lastProcess();
        $process->waitUntilOutputContains('Consuming messages', 15.0);
    }

    protected function assertConsumeExitsSuccessfully(E2eConsumeProcess|null $process = null): void
    {
        $process ??= $this->lastProcess();
        $exitCode = $process->wait(30.0);

        self::assertSame(0, $exitCode, 'console command failed.' . $process->debugOutput());
    }

    protected function consumeSupports(string $option): bool
    {
        if (! $this->consumeHelpLoaded) {
            $process = new E2eConsumeProcess();
            $process->start(['messenger:consume', '--help', '--no-interaction'], $this->env);
            $process->wait(15.0);
            $this->consumeHelp       = $process->stdout() . $process->stderr();
            $this->consumeHelpLoaded = true;
            $process->cleanupFiles();
        }

        return preg_match('/' . preg_quote($option, '/') . '(?:\s|=|\[|]|$)/', $this->consumeHelp) === 1;
    }

    protected function bus(): MessageBusInterface
    {
        $bus = $this->container()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    protected function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer();

        if ($container->has('test.service_container')) {
            $container = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
        }

        return $container;
    }

    protected function amqpTransport(string $name): AmqpTransport
    {
        $transport = $this->container()->get('messenger.transport.' . $name);
        self::assertInstanceOf(AmqpTransport::class, $transport);

        return $transport;
    }

    protected function messageCount(string $name): int
    {
        return $this->amqpTransport($name)->getMessageCount();
    }

    protected function assertQueueEventuallyEmpty(string $name, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            try {
                if ($this->messageCount($name) === 0) {
                    return;
                }
            } catch (Throwable) {
            }

            usleep(50_000);
        }

        self::fail(sprintf('Transport %s still has %d message(s)', $name, $this->messageCount($name)));
    }

    protected function setupTransport(string $name): void
    {
        $transport = $this->container()->get('messenger.transport.' . $name);

        if (! $transport instanceof AmqpTransport) {
            return;
        }

        $transport->setup();
    }

    /** @return E2eRecord */
    protected function waitForRecord(string $type, string $id, float $timeout = 10.0, bool $failed = false): array
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            foreach ($this->recordsOfType($type, $failed) as $record) {
                if ($record['id'] === $id) {
                    return $record;
                }
            }

            $status = $this->lastProcessOrNull()?->status();

            if ($status !== null && $status['running'] === false) {
                break;
            }

            usleep(20_000);
        }

        self::fail(sprintf(
            'Timed out waiting for %s id=%s failed=%s.%s',
            $type,
            $id,
            $failed ? 'true' : 'false',
            $this->lastProcessOrNull()?->debugOutput() ?? '',
        ));
    }

    /** @return list<E2eRecord> */
    protected function recordsOfType(string $type, bool|null $failed = false): array
    {
        $matched = [];

        foreach ($this->readRecords() as $record) {
            if ($record['type'] !== $type) {
                continue;
            }

            if ($failed !== null && ($record['failed'] ?? false) !== $failed) {
                continue;
            }

            $matched[] = $record;
        }

        return $matched;
    }

    /**
     * @param list<E2eRecord> $records
     *
     * @return list<string>
     */
    protected function idsOf(array $records): array
    {
        $ids = [];

        foreach ($records as $record) {
            $ids[] = $record['id'];
        }

        return $ids;
    }

    protected function uniqueId(string $label): string
    {
        return $label . '_' . uniqid();
    }

    protected function parentPid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }

    protected function dsnForExchange(string $exchange, string|null $base = null): string
    {
        $base ??= getenv('MESSENGER_TRANSPORT_PHPAMQPLIB_DSN');

        if ($base === false || $base === '') {
            $base = 'phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages';
        }

        if (preg_match('#^(phpamqplibs?://.*/)[^/?]+(\?.*)?$#', $base, $matches) !== 1) {
            self::fail('Could not derive an E2E DSN from ' . TestLog::redactDsn($base));
        }

        return $matches[1] . $exchange . ($matches[2] ?? '');
    }

    private function sslDsnForPrefix(string $prefix): string
    {
        $sslBase = getenv('MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN');

        if ($sslBase !== false && $sslBase !== '') {
            return $this->dsnForExchange($prefix . '_ssl', $sslBase);
        }

        return $this->dsnForExchange($prefix . '_ssl');
    }

    /** @param list<float> $gaps */
    protected function formatGaps(array $gaps): string
    {
        $formatted = [];

        foreach ($gaps as $gap) {
            $formatted[] = sprintf('%.3f', $gap);
        }

        return implode(',', $formatted);
    }

    protected function lastProcess(): E2eConsumeProcess
    {
        $process = $this->lastProcessOrNull();
        self::assertNotNull($process);

        return $process;
    }

    private function lastProcessOrNull(): E2eConsumeProcess|null
    {
        if ($this->processes === []) {
            return null;
        }

        return $this->processes[count($this->processes) - 1];
    }

    private function archiveTestLogs(): void
    {
        if ($this->logFile === '') {
            return;
        }

        /** @psalm-suppress InternalMethod */
        $name = TestLog::safeBasename(static::class . '_' . $this->name());

        TestLog::copyFile($this->logFile, $name . '.e2e.jsonl');
        TestLog::copyFile($this->debugLogFile, $name . '.debug.ndjson');

        foreach ($this->processes as $index => $process) {
            TestLog::write($name . '.consume-' . $index . '.stdout.txt', TestLog::redact($process->stdout()));
            TestLog::write($name . '.consume-' . $index . '.stderr.txt', TestLog::redact($process->stderr()));
        }
    }

    private function clearMessengerRestartSignal(): void
    {
        try {
            foreach (['cache.messenger.restart_workers_signal', 'cache.app'] as $id) {
                if (! $this->container()->has($id)) {
                    continue;
                }

                /** @psalm-suppress MixedAssignment */
                $pool = $this->container()->get($id);

                if ($pool instanceof CacheItemPoolInterface) {
                    $pool->deleteItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
                    $pool->clear();
                }
            }
        } catch (Throwable) {
        }
    }

    private function deleteTransportTopology(string $name): void
    {
        try {
            $transport = $this->container()->get('messenger.transport.' . $name);

            if (! $transport instanceof AmqpTransport) {
                return;
            }

            $connection = $transport->getConnection();
            $channel    = $connection->channel();
            $exchange   = $connection->getConfig()->exchange->name;

            foreach ($connection->getQueueNames() as $queueName) {
                $channel->queue_delete($queueName);
            }

            if ($exchange !== '') {
                $channel->exchange_delete($exchange);
            }

            $connection->close();
        } catch (Throwable) {
        }
    }

    /** @param array<string, string> $values */
    private function applyEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            $_SERVER[$name] = $value;
            $_ENV[$name]    = $value;
            putenv($name . '=' . $value);
        }
    }

    /** @return list<E2eRecord> */
    private function readRecords(): array
    {
        if (! is_file($this->logFile)) {
            return [];
        }

        $contents = file_get_contents($this->logFile);

        if ($contents === false || $contents === '') {
            return [];
        }

        $records = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['t'], $decoded['type'], $decoded['id'])) {
                continue;
            }

            $records[] = [
                't' => (float) $decoded['t'],
                'type' => (string) $decoded['type'],
                'id' => (string) $decoded['id'],
                'failed' => (bool) ($decoded['failed'] ?? false),
                'will_retry' => (bool) ($decoded['will_retry'] ?? false),
                'pid' => (int) ($decoded['pid'] ?? 0),
                'transport' => (string) ($decoded['transport'] ?? ''),
                'queue' => isset($decoded['queue']) ? (string) $decoded['queue'] : null,
                'message_id' => isset($decoded['message_id']) ? (string) $decoded['message_id'] : null,
            ];
        }

        return $records;
    }
}
