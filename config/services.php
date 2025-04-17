<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jwage\PhpAmqpLibMessengerBundle\Middleware\AmqpFlushMiddlware;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use Psr\Log\LoggerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(AmqpFlushMiddlware::class);
    $services->set(AmqpTransportFactory::class)
        ->args([
            inline_service(ConnectionFactory::class)
                ->args([
                    inline_service(DsnParser::class),
                    inline_service(RetryFactory::class)
                        ->args([
                            service(LoggerInterface::class),
                        ]),
                    inline_service(AmqpConnectionFactory::class),
                ]),
            inline_service(RetryFactory::class)
                ->args([
                    service(LoggerInterface::class),
                ]),
        ])
        ->tag('messenger.transport_factory');
};
