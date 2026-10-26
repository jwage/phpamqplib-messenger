<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Tests\E2e\E2eMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\E2e\E2eSslMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\E2e\E2eTestCase;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Throwable;

use function class_exists;
use function interface_exists;
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

    public function testMessengerConsumeWithKeepaliveRecoversAfterBrokerRestart(): void
    {
        if (! interface_exists(KeepaliveReceiverInterface::class)) {
            self::markTestSkipped('messenger:consume --keepalive requires Symfony >= 7.2');
        }

        if (! class_exists(SignalRegistry::class) || ! SignalRegistry::isSupported()) {
            self::markTestSkipped('messenger:consume --keepalive requires pcntl signals');
        }

        if (! $this->consumeSupports('--keepalive')) {
            self::markTestSkipped('messenger:consume --keepalive is not available');
        }

        $id = $this->uniqueId('keepalive_restart');

        $consume = $this->startConsume(['e2e_high'], limit: 1, timeLimit: 40, extra: ['--keepalive=1']);
        $this->waitUntilConsuming($consume);

        // Let the keepalive alarm interrupt the socket wait before and after the broker drops the connection.
        usleep(2_500_000);
        $this->harness->broker('restart');
        $this->harness->waitUntilReady();

        $this->dispatchUntilSuccess(new E2eMessage($id));
        $this->waitForRecord(E2eMessage::class, $id, timeout: 30.0);
        $this->assertConsumeExitsSuccessfully($consume);

        self::assertStringNotContainsString('Interrupted system call', $consume->debugOutput());
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
