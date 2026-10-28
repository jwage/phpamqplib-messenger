<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

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
            cafile: ConfigHelper::getString($sslConfig, 'cafile'),
            capath: ConfigHelper::getString($sslConfig, 'capath'),
            localCert: ConfigHelper::getString($sslConfig, 'local_cert'),
            localPk: ConfigHelper::getString($sslConfig, 'local_pk'),
            verifyPeer: ConfigHelper::getBool($sslConfig, 'verify_peer'),
            verifyPeerName: ConfigHelper::getBool($sslConfig, 'verify_peer_name'),
            passphrase: ConfigHelper::getString($sslConfig, 'passphrase'),
            ciphers: ConfigHelper::getString($sslConfig, 'ciphers'),
            securityLevel: ConfigHelper::getInt($sslConfig, 'security_level'),
            cryptoMethod: ConfigHelper::getInt($sslConfig, 'crypto_method'),
        );
    }

    /**
     * @param array<string, mixed> $sslConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $sslConfig): void
    {
        ConfigHelper::validate('ssl', $sslConfig, self::AVAILABLE_OPTIONS);
    }
}
