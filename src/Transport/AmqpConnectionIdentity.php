<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;

use function hash;
use function json_encode;
use function ksort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class AmqpConnectionIdentity
{
    public function __construct(
        private string $key,
    ) {
    }

    public static function fromConnectionConfig(ConnectionConfig $connectionConfig): self
    {
        return new self(self::buildKey($connectionConfig));
    }

    public function toString(): string
    {
        return $this->key;
    }

    private static function buildKey(ConnectionConfig $connectionConfig): string
    {
        $normalizedSsl = null;

        if ($connectionConfig->ssl !== null) {
            $normalizedSsl = [
                'cafile' => $connectionConfig->ssl->cafile,
                'capath' => $connectionConfig->ssl->capath,
                'local_cert' => $connectionConfig->ssl->localCert,
                'local_pk' => $connectionConfig->ssl->localPk,
                'verify_peer' => $connectionConfig->ssl->verifyPeer,
                'verify_peer_name' => $connectionConfig->ssl->verifyPeerName,
                'passphrase' => $connectionConfig->ssl->passphrase,
                'ciphers' => $connectionConfig->ssl->ciphers,
                'security_level' => $connectionConfig->ssl->securityLevel,
                'crypto_method' => $connectionConfig->ssl->cryptoMethod,
            ];
            ksort($normalizedSsl);
        }

        $normalized = [
            'host' => $connectionConfig->host,
            'port' => $connectionConfig->port,
            'user' => $connectionConfig->user,
            'password' => $connectionConfig->password,
            'vhost' => $connectionConfig->vhost,
            'insist' => $connectionConfig->insist,
            'login_method' => $connectionConfig->loginMethod,
            'locale' => $connectionConfig->locale,
            'connect_timeout' => $connectionConfig->connectTimeout,
            'read_timeout' => $connectionConfig->readTimeout,
            'write_timeout' => $connectionConfig->writeTimeout,
            'rpc_timeout' => $connectionConfig->rpcTimeout,
            'heartbeat' => $connectionConfig->heartbeat,
            'keepalive' => $connectionConfig->keepalive,
            'connection_name' => $connectionConfig->connectionName,
            'ssl' => $normalizedSsl,
        ];

        $canonicalJson = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $canonicalJson);
    }
}
