<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
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

    $services->set(Debug::class)
        ->args([
            service(LoggerInterface::class),
            param('kernel.debug'),
        ]);

    $services->set(ConsumerWaitCoordinator::class)
        ->args([
            service(Debug::class),
        ]);

    $services->set(AmqpWorkerListener::class)
        ->args([
            service(ConsumerWaitCoordinator::class),
            service(Debug::class),
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
                    service(Debug::class),
                ]),
            service(AmqpWorkerListener::class),
        ])
        ->tag('messenger.transport_factory');
};
