<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

use function mt_rand;
use function usleep;

class Retry
{
    /** @var positive-int|0 $defaultRetries */
    public static int $defaultRetries = 2;

    /** @var positive-int|0 $defaultWaitTime */
    public static int $defaultWaitTime = 1000;

    public static bool $defaultJitter = true;

    private int $retries;

    /** @var positive-int|0 $waitTime */
    private int $waitTime;

    private bool $jitter;

    private LoggerInterface|null $logger = null;

    private string|null $exceptionClass = null;

    /** @param positive-int|0 $waitTime */
    public function __construct(
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ) {
        $this->retries  = $retries ?? self::$defaultRetries;
        $this->waitTime = $waitTime ?? self::$defaultWaitTime;
        $this->jitter   = $jitter ?? self::$defaultJitter;
    }

    public function catch(string $exceptionClass): self
    {
        $this->exceptionClass = $exceptionClass;

        return $this;
    }

    public function setLogger(LoggerInterface|null $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @throws Throwable
     *
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress UnusedVariable
     */
    public function run(Closure $callable): mixed
    {
        $isRetry = false;

        beginning:

        try {
            return $callable($this, $isRetry);
        } catch (Throwable $e) {
            if ($this->exceptionClass !== null && ! $e instanceof $this->exceptionClass) {
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

            $isRetry = true;

            goto beginning;
        }
    }

    /** @return int<0, max> */
    private function getTimeToWait(): int
    {
        if ($this->jitter) {
            return mt_rand(0, $this->waitTime);
        }

        return $this->waitTime;
    }
}
