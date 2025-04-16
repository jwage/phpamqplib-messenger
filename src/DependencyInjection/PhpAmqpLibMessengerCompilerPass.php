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

use function array_keys;
use function sprintf;

class PhpAmqpLibMessengerCompilerPass implements CompilerPassInterface
{
    /** @throws InvalidArgumentException */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $transportRefs = [];

        foreach (array_keys($container->findTaggedServiceIds('messenger.receiver')) as $serviceId) {
            $transportRefs[] = new Reference($serviceId);
        }

        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $batchBusId = sprintf('%s.batch', $busId);

            $container->register($batchBusId, BatchMessageBus::class)
                ->setArguments([
                    new Reference($busId),
                    $transportRefs,
                ]);

            $container->registerAliasForArgument(
                $batchBusId,
                BatchMessageBusInterface::class,
                $busId,
            );
        }

        if (! $container->hasAlias('messenger.default_bus')) {
            return;
        }

        $defaultBusId      = (string) $container->getAlias('messenger.default_bus');
        $defaultBatchBusId = sprintf('%s.batch', $defaultBusId);

        $container->setAlias(BatchMessageBusInterface::class, $defaultBatchBusId);
        $container->setAlias('messenger.default_bus.batch', $defaultBatchBusId);
    }
}
