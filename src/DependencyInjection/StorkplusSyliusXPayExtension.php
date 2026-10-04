<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class StorkplusSyliusXPayExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{api_base_url: string, timeout: float} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('storkplus_sylius_xpay.api_base_url', $config['api_base_url']);
        $container->setParameter('storkplus_sylius_xpay.timeout', $config['timeout']);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.xml');
    }

    public function getAlias(): string
    {
        return 'storkplus_sylius_xpay';
    }
}
