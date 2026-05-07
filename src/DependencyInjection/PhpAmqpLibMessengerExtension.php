<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\DependencyInjection;

use Exception;
use Override;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** @psalm-suppress InternalClass */
class PhpAmqpLibMessengerExtension extends Extension
{
    #[Override]
    public function getAlias(): string
    {
        return 'php_amqp_lib_messenger';
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $configs
     *
     * @throws Exception
     *
     * {@inheritDoc}
     */
    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('php_amqp_lib_messenger.connection_reuse', $config['connection_reuse']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }
}
