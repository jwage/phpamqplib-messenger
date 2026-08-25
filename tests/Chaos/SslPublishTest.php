<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Throwable;

use function dirname;
use function str_contains;
use function strtolower;

class SslPublishTest extends ChaosTestCase
{
    public function testPublishAndConsumeOverTls(): void
    {
        $name       = $this->harness->topologyName('ssl');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2], [
            'ssl' => [
                'cafile' => dirname(__DIR__) . '/fixtures/ssl/ca.pem',
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]), $this->harness->sslDsn());

        $connection->publish(body: 'over-tls');
        self::assertSame(1, $connection->countMessagesInQueues());

        $envelope = $this->harness->consumeOne($connection, $name);
        $envelope->ack();
        self::assertSame(0, $connection->countMessagesInQueues());
    }

    public function testPublishFailsWhenPeerVerificationRejectsTheSelfSignedCertificate(): void
    {
        $name       = $this->harness->topologyName('ssl_verify');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'connect_timeout' => 2,
            'read_timeout' => 5,
            'write_timeout' => 5,
            'rpc_timeout' => 2,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]), $this->harness->sslDsn());

        try {
            $connection->publish(body: 'should-not-publish');
            $published = true;
        } catch (Throwable $exception) {
            $published = false;
            $haystack  = strtolower($exception->getMessage() . ' ' . ($exception->getPrevious()?->getMessage() ?? ''));
            self::assertTrue(
                str_contains($haystack, 'ssl')
                || str_contains($haystack, 'certificate')
                || str_contains($haystack, 'verify')
                || str_contains($haystack, 'peer')
                || str_contains($haystack, 'self signed')
                || str_contains($haystack, 'self-signed'),
                'peer verification should fail TLS handshake, got: ' . $exception->getMessage(),
            );
        }

        self::assertFalse($published, 'Expected TLS peer verification to reject the self-signed compose certificate');
    }
}
