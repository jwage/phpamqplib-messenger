<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\ConfirmMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\TransactionMessage;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /** @inheritDoc */
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new PhpAmqpLibMessengerBundle(),
        ];
    }

    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('bus1.batch')
            ->setPublic(true);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setParameter(
                'env(MESSENGER_TRANSPORT_PHPAMQPLIB_DSN)',
                'phpamqplib://guest:guest@127.0.0.1/%2f/messages',
            );

            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'php_errors' => ['log' => true],
                'messenger' => [
                    'default_bus' => 'bus1',
                    'buses' => [
                        'bus1' => [],
                        'bus2' => [],
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
                    ],
                    'routing' => [
                        ConfirmMessage::class => 'with_confirms',
                        TransactionMessage::class => 'with_transactions',
                    ],
                ],
            ]);
        });
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }
}
