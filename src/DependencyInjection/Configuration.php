<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('php_amqp_lib_messenger');
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->enumNode('connection_reuse')
                    ->values(['none', 'producer-consumer', 'all'])
                    ->defaultValue('none')
                    ->info(
                        'Default none matches behavior before the connection registry: one TCP connection ' .
                        'per Connection wrapper (no cross-transport sharing). ' .
                        'none: same as that legacy default (best isolation, e.g. CloudAMQP). ' .
                        'producer-consumer: share TCP only within the same connection_role. ' .
                        'all: share TCP for the same broker identity across roles (fewest connections).',
                    )
                ->end()
            ->end();

        return $treeBuilder;
    }
}
