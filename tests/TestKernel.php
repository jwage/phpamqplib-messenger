<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Middleware\DeduplicationPluginMiddleware;
use Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\ConfirmMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\TransactionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;
use function sys_get_temp_dir;

class TestKernel extends Kernel implements CompilerPassInterface
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

    public function process(ContainerBuilder $container): void
    {
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setParameter(
                'env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)',
                'phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages',
            );

            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'php_errors' => ['log' => true],
                'messenger' => [
                    'default_bus' => 'bus1',
                    'buses' => [
                        'bus1' => [
                            'middleware' => [
                                DeduplicationPluginMiddleware::class,
                            ],
                        ],
                        'bus2' => [
                            'middleware' => [
                                DeduplicationPluginMiddleware::class,
                            ],
                        ],
                    ],
                    'transports' => [
                        'with_confirms' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)%',
                            'options' => [
                                'transactions_enabled' => false,
                                'confirm_enabled' => true,
                                'prefetch_count' => 10,
                                'wait_timeout' => 0.10, // lower wait_timeout for tests
                                'exchange' => ['name' => 'test_confirms_exchange'],
                                'queues' => [
                                    'test_confirms_queue' => ['prefetch_count' => 2],
                                ],
                            ],
                        ],
                        'with_transactions' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)%',
                            'options' => [
                                'transactions_enabled' => true,
                                'confirm_enabled' => false,
                                'prefetch_count' => 10,
                                'wait_timeout' => 0.10, // lower wait_timeout for tests
                                'exchange' => ['name' => 'test_transactions_exchange'],
                                'queues' => [
                                    'test_transactions_queue' => ['prefetch_count' => 2],
                                ],
                            ],
                        ],
                        'with_fetch_size' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)%',
                            'options' => [
                                'transactions_enabled' => false,
                                'confirm_enabled' => true,
                                'fetch_size' => 2,
                                'wait_timeout' => 0.10, // lower wait_timeout for tests
                                'exchange' => ['name' => 'test_fetch_size_exchange'],
                                'queues' => [
                                    'test_fetch_size_queue' => ['prefetch_count' => 5],
                                ],
                            ],
                        ],
                        'with_multiple_queues' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)%',
                            'options' => [
                                'transactions_enabled' => false,
                                'confirm_enabled' => true,
                                'wait_timeout' => 0.10, // lower wait_timeout for tests
                                'exchange' => [
                                    'name' => 'test_multiple_queues_exchange',
                                    'type' => 'direct',
                                ],
                                'queues' => [
                                    'test_multiple_queues_order' => [
                                        'binding_keys' => ['order'],
                                    ],
                                    'test_multiple_queues_quote' => [
                                        'binding_keys' => ['quote'],
                                    ],
                                ],
                            ],
                        ],
                        'with_no_ack' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)%',
                            'options' => [
                                'transactions_enabled' => false,
                                'confirm_enabled' => true,
                                'no_ack' => true,
                                'wait_timeout' => 0.10, // lower wait_timeout for tests
                                'exchange' => ['name' => 'test_no_ack_exchange'],
                                'queues' => [
                                    'test_no_ack_queue' => [],
                                ],
                            ],
                        ],
                    ],
                    'routing' => [
                        ConfirmMessage::class => 'with_confirms',
                        TransactionMessage::class => 'with_transactions',
                    ],
                ],
            ]);

            $container->setParameter('env(TEST_FUNCTIONAL_LOG)', sys_get_temp_dir() . '/phpamqplib-functional.ndjson');

            $container->register('logger', FileLogger::class)
                ->setArgument('$file', '%env(TEST_FUNCTIONAL_LOG)%')
                ->setPublic(true);
            $container->setAlias(LoggerInterface::class, 'logger')
                ->setPublic(true);
        });
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }
}
