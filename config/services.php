<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use Psr\Log\LoggerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(AMQPConnectionFactory::class);

    $services->set(AMQPTransportFactory::class)
        ->args([
            service(ConnectionFactory::class),
            service(RetryFactory::class),
        ])
        ->tag('messenger.transport_factory');

    $services->set(ConnectionFactory::class)
        ->args([
            service(DsnParser::class),
            service(RetryFactory::class),
            service(AMQPConnectionFactory::class),
        ]);

    $services->set(DsnParser::class);

    $services->set(Retry::class);

    $services->set(RetryFactory::class)
        ->args([
            service(LoggerInterface::class),
        ]);
};
