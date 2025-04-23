<?php

declare(strict_types=1);

namespace App\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;
use PHPUnit\Framework\TestCase;
use stdClass;

class SslConfigTest extends TestCase
{
    public function testDefaultConstruct(): void
    {
        $sslConfig = new SslConfig();

        self::assertNull($sslConfig->cafile);
        self::assertNull($sslConfig->capath);
        self::assertNull($sslConfig->localCert);
        self::assertNull($sslConfig->localPk);
        self::assertNull($sslConfig->verifyPeer);
        self::assertNull($sslConfig->verifyPeerName);
        self::assertNull($sslConfig->passphrase);
        self::assertNull($sslConfig->ciphers);
        self::assertNull($sslConfig->securityLevel);
        self::assertNull($sslConfig->cryptoMethod);
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $sslConfig = SslConfig::fromArray([]);

        self::assertNull($sslConfig->cafile);
        self::assertNull($sslConfig->capath);
        self::assertNull($sslConfig->localCert);
        self::assertNull($sslConfig->localPk);
        self::assertNull($sslConfig->verifyPeer);
        self::assertNull($sslConfig->verifyPeerName);
        self::assertNull($sslConfig->passphrase);
        self::assertNull($sslConfig->ciphers);
        self::assertNull($sslConfig->securityLevel);
        self::assertNull($sslConfig->cryptoMethod);
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidOptions(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid ssl option(s) "invalid" passed to the AMQP Messenger transport - known options:');

        SslConfig::fromArray(['invalid' => true]);
    }

    public function testFromArray(): void
    {
        $sslConfig = SslConfig::fromArray([
            'cafile' => 'cafile',
            'capath' => 'capath',
            'local_cert' => 'local_cert',
            'local_pk' => 'local_pk',
            'verify_peer' => true,
            'verify_peer_name' => true,
            'passphrase' => 'passphrase',
            'ciphers' => 'ciphers',
            'security_level' => 1,
            'crypto_method' => 1,
        ]);

        self::assertSame('cafile', $sslConfig->cafile);
        self::assertSame('capath', $sslConfig->capath);
        self::assertSame('local_cert', $sslConfig->localCert);
        self::assertSame('local_pk', $sslConfig->localPk);
        self::assertTrue($sslConfig->verifyPeer);
        self::assertTrue($sslConfig->verifyPeerName);
        self::assertSame('passphrase', $sslConfig->passphrase);
        self::assertSame('ciphers', $sslConfig->ciphers);
        self::assertSame(1, $sslConfig->securityLevel);
        self::assertSame(1, $sslConfig->cryptoMethod);
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidTypes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "object" for key "cafile" (expected string)');

        SslConfig::fromArray(['cafile' => new stdClass()]);
    }
}
