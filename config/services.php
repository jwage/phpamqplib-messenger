<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Middleware\DeduplicationPluginMiddleware;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use Psr\Log\LoggerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DeduplicationPluginMiddleware::class);

    $services->set(ConsumerWaitCoordinator::class);

    $services->set(AmqpWorkerListener::class)
        ->args([
            service(ConsumerWaitCoordinator::class),
        ])
        ->tag('kernel.event_subscriber');

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
                    service(LoggerInterface::class),
                ]),
            service(AmqpWorkerListener::class),
        ])
        ->tag('messenger.transport_factory');
};
