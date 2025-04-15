<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function sprintf;

final readonly class SslConfig
{
    private const array AVAILABLE_OPTIONS = [
        'cafile',
        'capath',
        'local_cert',
        'local_pk',
        'verify_peer',
        'verify_peer_name',
        'passphrase',
        'ciphers',
        'security_level',
        'crypto_method',
    ];

    public function __construct(
        public string|null $cafile = null,
        public string|null $capath = null,
        public string|null $localCert = null,
        public string|null $localPk = null,
        public bool|null $verifyPeer = null,
        public bool|null $verifyPeerName = null,
        public string|null $passphrase = null,
        public string|null $ciphers = null,
        public int|null $securityLevel = null,
        public int|null $cryptoMethod = null,
    ) {
    }

    /**
     * @param array{
     *     cafile?: string|null,
     *     capath?: string|null,
     *     local_cert?: string|null,
     *     local_pk?: string|null,
     *     verify_peer?: bool|null,
     *     verify_peer_name?: bool|null,
     *     passphrase?: string|null,
     *     ciphers?: string|null,
     *     security_level?: int|null,
     *     crypto_method?: int|null,
     * } $sslConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $sslConfig): self
    {
        self::validate($sslConfig);

        return new self(
            $sslConfig['cafile'] ?? null,
            $sslConfig['capath'] ?? null,
            $sslConfig['local_cert'] ?? null,
            $sslConfig['local_pk'] ?? null,
            $sslConfig['verify_peer'] ?? null,
            $sslConfig['verify_peer_name'] ?? null,
            $sslConfig['passphrase'] ?? null,
            $sslConfig['ciphers'] ?? null,
            $sslConfig['security_level'] ?? null,
            $sslConfig['crypto_method'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $sslConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $sslConfig): void
    {
        if (0 < count($invalidOptions = array_diff(array_keys($sslConfig), self::AVAILABLE_OPTIONS))) {
            throw new InvalidArgumentException(sprintf('Invalid ssl option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidOptions)));
        }
    }
}
