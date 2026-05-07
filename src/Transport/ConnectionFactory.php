<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

use function bin2hex;
use function random_bytes;

class ConnectionFactory
{
    private AmqpConnectionReuse $defaultConnectionReuse;

    public function __construct(
        private DsnParser $dsnParser,
        private RetryFactory $retryFactory,
        private AmqpConnectionRegistry $amqpConnectionRegistry,
        /** @param 'none'|'producer-consumer'|'all' $defaultConnectionReuse Matches pre-registry default: no TCP sharing across wrappers. */
        string|AmqpConnectionReuse $defaultConnectionReuse = 'none',
        private LoggerInterface|null $logger = null,
    ) {
        $this->defaultConnectionReuse = $defaultConnectionReuse instanceof AmqpConnectionReuse
            ? $defaultConnectionReuse
            : AmqpConnectionReuse::fromConfigString($defaultConnectionReuse);
    }

    /**
     * Messenger transport options (not AMQP broker options):
     * - connection_reuse: none|producer-consumer|all (overrides bundle default)
     * - connection_role: producer|consumer|mixed (for producer-consumer; default mixed)
     *
     * @param array<array-key, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    public function fromDsn(
        #[SensitiveParameter]
        string $dsn,
        array $options = [],
    ): Connection {
        $rawReuse = $options['connection_reuse'] ?? null;
        $rawRole  = $options['connection_role'] ?? null;
        unset($options['connection_reuse'], $options['connection_role']);

        $reuse = $rawReuse !== null
            ? AmqpConnectionReuse::fromConfigString((string) $rawReuse)
            : $this->defaultConnectionReuse;
        $role  = $rawRole !== null
            ? AmqpConnectionRole::fromConfigString((string) $rawRole)
            : AmqpConnectionRole::MIXED;

        $connectionConfig  = $this->dsnParser->parseDsn($dsn, $options);
        $brokerIdentity    = AmqpConnectionIdentity::fromConnectionConfig($connectionConfig);

        $dedicatedInstanceId = '';
        if ($reuse === AmqpConnectionReuse::NONE) {
            $dedicatedInstanceId = bin2hex(random_bytes(16));
        }

        $registryKey = AmqpConnectionRegistryKey::create(
            $brokerIdentity,
            $reuse,
            $role,
            $dedicatedInstanceId,
        );

        return new Connection(
            $this->retryFactory,
            $this->amqpConnectionRegistry,
            $registryKey,
            $connectionConfig,
            $this->logger,
        );
    }
}
