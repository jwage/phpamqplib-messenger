<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\DependencyInjection;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBus;
use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBusInterface;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function str_starts_with;

class PhpAmqpLibMessengerCompilerPass implements CompilerPassInterface
{
    /** @throws InvalidArgumentException */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $serviceIds = $container->getServiceIds();

        $transportServiceReferences = [];

        foreach ($serviceIds as $serviceId) {
            if (! str_starts_with($serviceId, 'messenger.transport.')) {
                continue;
            }

            $definition = $container->getDefinition($serviceId);

            if ($definition->getClass() !== TransportInterface::class) {
                continue;
            }

            $transportServiceReferences[] = new Reference($serviceId);
        }

        $container->register(BatchMessageBus::class)
            ->setClass(BatchMessageBus::class)
            ->setArguments([
                new Reference(MessageBusInterface::class),
                $transportServiceReferences,
            ]);

        $container->setAlias(BatchMessageBusInterface::class, BatchMessageBus::class);
    }
}
