<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jwage\PhpAmqpLibMessengerBundle\Middleware\DeduplicationPluginMiddleware;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistry;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use Psr\Log\LoggerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DeduplicationPluginMiddleware::class);

    $services->set(AmqpTransportFactory::class)
        ->args([
            inline_service(ConnectionFactory::class)
                ->args([
                    inline_service(DsnParser::class),
                    inline_service(RetryFactory::class)
                        ->args([
                            service(LoggerInterface::class),
                        ]),
                    inline_service(AmqpConnectionRegistry::class)
                        ->args([
                            inline_service(AmqpConnectionFactory::class),
                        ]),
                    param('php_amqp_lib_messenger.connection_reuse'),
                    service(LoggerInterface::class),
                ]),
        ])
        ->tag('messenger.transport_factory');
};
