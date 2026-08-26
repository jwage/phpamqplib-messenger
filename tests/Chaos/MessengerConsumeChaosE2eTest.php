<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Tests\E2e\E2eMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\E2e\E2eSslMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\E2e\E2eTestCase;
use Throwable;

use function microtime;
use function putenv;
use function sprintf;
use function usleep;

class MessengerConsumeChaosE2eTest extends E2eTestCase
{
    private Harness $harness;

    public function testMessengerConsumeRecoversAfterBrokerRestart(): void
    {
        $id = $this->uniqueId('restart');

        $this->startConsume(['e2e_high'], limit: 1, timeLimit: 40);
        $this->waitUntilConsuming();

        $this->harness->broker('restart');
        $this->harness->waitUntilReady();

        $this->dispatchUntilSuccess(new E2eMessage($id));
        $this->waitForRecord(E2eMessage::class, $id, timeout: 30.0);
        $this->assertConsumeExitsSuccessfully();
    }

    public function testMessengerConsumeWorksOverTls(): void
    {
        $this->setupTransport('e2e_ssl');

        $id = $this->uniqueId('tls');
        $this->dispatchUntilSuccess(new E2eSslMessage($id));

        $this->startConsume(['e2e_ssl'], limit: 1, timeLimit: 20);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eSslMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_ssl');
    }

    protected function setUp(): void
    {
        $this->harness = new Harness();
        $this->harness->waitUntilReady();

        $sslDsn = $this->harness->sslDsn();
        putenv(sprintf('MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN=%s', $sslDsn));
        $_ENV['MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN']    = $sslDsn;
        $_SERVER['MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN'] = $sslDsn;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->harness->cleanup();
    }

    private function dispatchUntilSuccess(object $message): void
    {
        $deadline = microtime(true) + 15.0;
        $last     = null;

        while (microtime(true) < $deadline) {
            try {
                $this->bus()->dispatch($message);

                return;
            } catch (Throwable $exception) {
                $last = $exception;
                usleep(200_000);
            }
        }

        self::fail('Timed out dispatching after broker recovery: ' . ($last?->getMessage() ?? 'unknown error'));
    }
}
