<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

use function assert;
use function is_array;
use function mt_rand;
use function usleep;

class Retry
{
    /** @var positive-int|0 $defaultRetries */
    public static int $defaultRetries = 10;

    /** @var positive-int|0 $defaultWaitTime */
    public static int $defaultWaitTime = 2000;

    public static bool $defaultJitter = true;

    private int $retries;

    /** @var positive-int|0 $waitTime */
    private int $waitTime;

    private bool $jitter;

    private LoggerInterface|null $logger = null;

    /** @var array<class-string> */
    private array $catch = [Throwable::class];

    private bool $isRetry = false;

    private Closure|null $beforeRetry = null;

    /** @param positive-int|0 $waitTime */
    public function __construct(
        private Closure|null $run = null,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ) {
        $this->retries  = $retries ?? self::$defaultRetries;
        $this->waitTime = $waitTime ?? self::$defaultWaitTime;
        $this->jitter   = $jitter ?? self::$defaultJitter;
    }

    /** @param array<class-string>|class-string $catch */
    public function catch(array|string $catch): self
    {
        if (! is_array($catch)) {
            $catch = [$catch];
        }

        $this->catch = $catch;

        return $this;
    }

    public function setLogger(LoggerInterface|null $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function beforeRetry(Closure $beforeRetry): self
    {
        $this->beforeRetry = $beforeRetry;

        return $this;
    }

    /**
     * @throws Throwable
     *
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress UnusedVariable
     */
    public function run(Closure|null $callable = null): mixed
    {
        if ($callable === null) {
            $callable = $this->run;
        }

        assert($callable !== null);

        beginning:

        try {
            if ($this->isRetry === true && $this->beforeRetry !== null) {
                ($this->beforeRetry)($this);
            }

            return $callable($this);
        } catch (Throwable $e) {
            if (! $this->isExceptionToCatch($e)) {
                throw $e;
            }

            if (! $this->retries) {
                throw $e;
            }

            $this->logger?->info('Retrying {message}', [
                'message' => $e->getMessage(),
                'retries' => $this->retries,
                'callable' => $callable,
                'exception' => $e,
            ]);

            $this->retries--;

            usleep($this->getTimeToWait() * 1000);

            $this->isRetry = true;

            goto beginning;
        }

        $this->isRetry = false;
    }

    /** @return int<0, max> */
    private function getTimeToWait(): int
    {
        if ($this->jitter) {
            return mt_rand(0, $this->waitTime);
        }

        return $this->waitTime;
    }

    private function isExceptionToCatch(Throwable $e): bool
    {
        if ($this->catch === []) {
            return true;
        }

        foreach ($this->catch as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        return false;
    }
}
