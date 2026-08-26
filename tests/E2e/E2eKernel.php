<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use Jwage\PhpAmqpLibMessengerBundle\Middleware\DeduplicationPluginMiddleware;
use Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle;
use Jwage\PhpAmqpLibMessengerBundle\Tests\FileLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;
use function sys_get_temp_dir;

class E2eKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<object>
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     */
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new PhpAmqpLibMessengerBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $sslCa = dirname(__DIR__) . '/fixtures/ssl/ca.pem';

        $loader->load(static function (ContainerBuilder $container) use ($sslCa): void {
            $defaults = [
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_high',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_low',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_failed',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_tx',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_multi',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_greedy',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_manual',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_keepalive',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_ssl',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/e2e_auto',
            ];

            $container->setParameter('env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)', $defaults[0]);
            $container->setParameter('env(E2E_HIGH_DSN)', $defaults[1]);
            $container->setParameter('env(E2E_LOW_DSN)', $defaults[2]);
            $container->setParameter('env(E2E_FAILED_DSN)', $defaults[3]);
            $container->setParameter('env(E2E_TX_DSN)', $defaults[4]);
            $container->setParameter('env(E2E_MULTI_DSN)', $defaults[5]);
            $container->setParameter('env(E2E_GREEDY_DSN)', $defaults[6]);
            $container->setParameter('env(E2E_MANUAL_DSN)', $defaults[7]);
            $container->setParameter('env(E2E_KEEPALIVE_DSN)', $defaults[8]);
            $container->setParameter('env(E2E_SSL_DSN)', $defaults[9]);
            $container->setParameter('env(E2E_AUTO_DSN)', $defaults[10]);
            $container->setParameter('env(E2E_MULTI_EXCHANGE)', 'e2e_multi');
            $container->setParameter('env(E2E_ORDER_QUEUE)', 'e2e_order');
            $container->setParameter('env(E2E_QUOTE_QUEUE)', 'e2e_quote');
            $container->setParameter('env(E2E_LOG)', sys_get_temp_dir() . '/phpamqplib-e2e.log');
            $container->setParameter('env(E2E_DEBUG_LOG)', sys_get_temp_dir() . '/phpamqplib-e2e.debug.ndjson');

            $retry = [
                'max_retries' => 3,
                'delay' => 100,
                'multiplier' => 1,
                'max_delay' => 1000,
            ];

            /**
             * @param array<string, mixed> $options
             *
             * @return array{dsn: string, retry_strategy: array<string, int>, options: array<string, mixed>}
             */
            $amqp = static function (string $dsn, array $options = []) use ($retry): array {
                return [
                    'dsn' => $dsn,
                    'retry_strategy' => $retry,
                    'options' => $options + [
                        'auto_setup' => true,
                        'confirm_enabled' => true,
                        'wait_timeout' => 1,
                        'fetch_size' => 1,
                        'prefetch_count' => 1,
                    ],
                ];
            };

            $greedyOptions = [
                'auto_setup' => true,
                'confirm_enabled' => true,
                'wait_timeout' => 1,
                'prefetch_count' => 20,
            ];

            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'php_errors' => ['log' => true],
                'cache' => ['app' => 'cache.adapter.filesystem'],
                'messenger' => [
                    'failure_transport' => 'e2e_failed',
                    'default_bus' => 'bus',
                    'buses' => [
                        'bus' => [
                            'middleware' => [
                                DeduplicationPluginMiddleware::class,
                            ],
                        ],
                    ],
                    'transports' => [
                        'e2e_high' => $amqp('%env(E2E_HIGH_DSN)%'),
                        'e2e_low' => $amqp('%env(E2E_LOW_DSN)%'),
                        'e2e_failed' => $amqp('%env(E2E_FAILED_DSN)%', ['fetch_size' => 1]),
                        'e2e_tx' => $amqp('%env(E2E_TX_DSN)%', [
                            'confirm_enabled' => false,
                            'transactions_enabled' => true,
                        ]),
                        'e2e_multi' => $amqp('%env(E2E_MULTI_DSN)%', [
                            'exchange' => [
                                'name' => '%env(E2E_MULTI_EXCHANGE)%',
                                'type' => 'direct',
                            ],
                            'queues' => [
                                'order' => [
                                    'name' => '%env(E2E_ORDER_QUEUE)%',
                                    'binding_keys' => ['order'],
                                ],
                                'quote' => [
                                    'name' => '%env(E2E_QUOTE_QUEUE)%',
                                    'binding_keys' => ['quote'],
                                ],
                            ],
                        ]),
                        'e2e_greedy' => [
                            'dsn' => '%env(E2E_GREEDY_DSN)%',
                            'retry_strategy' => $retry,
                            'options' => $greedyOptions,
                        ],
                        'e2e_manual' => $amqp('%env(E2E_MANUAL_DSN)%', ['auto_setup' => false]),
                        'e2e_keepalive' => $amqp('%env(E2E_KEEPALIVE_DSN)%', [
                            'heartbeat' => 1,
                            'keepalive_enabled' => true,
                            'read_timeout' => 8,
                            'write_timeout' => 8,
                            'rpc_timeout' => 8,
                            'connect_timeout' => 3,
                        ]),
                        'e2e_ssl' => $amqp('%env(E2E_SSL_DSN)%', [
                            'ssl' => [
                                'cafile' => $sslCa,
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                            ],
                        ]),
                        'e2e_auto' => $amqp('%env(E2E_AUTO_DSN)%'),
                        'e2e_memory' => 'in-memory://',
                    ],
                    'routing' => [
                        E2eMessage::class => 'e2e_high',
                        E2eFollowupMessage::class => 'e2e_high',
                        E2eLowMessage::class => 'e2e_low',
                        E2eTxMessage::class => 'e2e_tx',
                        E2eRoutedMessage::class => 'e2e_multi',
                        E2eGreedyMessage::class => 'e2e_greedy',
                        E2eManualMessage::class => 'e2e_manual',
                        E2eKeepaliveMessage::class => 'e2e_keepalive',
                        E2eMemoryMessage::class => 'e2e_memory',
                        E2eSslMessage::class => 'e2e_ssl',
                        E2eAutoMessage::class => 'e2e_auto',
                    ],
                ],
            ]);

            $container->register(E2eRecordStore::class)
                ->setArgument('$logFile', '%env(E2E_LOG)%')
                ->setPublic(true);

            $container->autowire(E2eConsumeEventSubscriber::class)
                ->addTag('kernel.event_subscriber');

            $handler = $container->autowire(E2eMessageHandler::class);
            // Symfony 5.4 infers the handled type only from __invoke unless `handles` is set.
            $handler->addTag('messenger.message_handler', ['method' => '__invoke', 'handles' => E2eMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleFollowup', 'handles' => E2eFollowupMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleLow', 'handles' => E2eLowMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleTx', 'handles' => E2eTxMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleRouted', 'handles' => E2eRoutedMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleGreedy', 'handles' => E2eGreedyMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleManual', 'handles' => E2eManualMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleKeepalive', 'handles' => E2eKeepaliveMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleMemory', 'handles' => E2eMemoryMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleSsl', 'handles' => E2eSslMessage::class]);
            $handler->addTag('messenger.message_handler', ['method' => 'handleAuto', 'handles' => E2eAutoMessage::class]);

            $container->register('logger', FileLogger::class)
                ->setArgument('$file', '%env(E2E_DEBUG_LOG)%')
                ->setPublic(true);
            $container->setAlias(LoggerInterface::class, 'logger')
                ->setPublic(true);
        });
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/phpamqplib-messenger-e2e/' . ($_SERVER['E2E_CACHE'] ?? $this->environment) . ($this->isDebug() ? '-debug' : '');
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }
}
