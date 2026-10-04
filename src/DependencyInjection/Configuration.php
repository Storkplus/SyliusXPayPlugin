<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('storkplus_sylius_xpay');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('api_base_url')
                    ->info('XPay API base URL. Test and live share it; the key prefix selects the mode.')
                    ->defaultValue('https://api.xpay.app')
                    ->cannotBeEmpty()
                ->end()
                ->floatNode('timeout')
                    ->info('HTTP timeout in seconds for XPay API calls.')
                    ->defaultValue(20.0)
                    ->min(1.0)
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
