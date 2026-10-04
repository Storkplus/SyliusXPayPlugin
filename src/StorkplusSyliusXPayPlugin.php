<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin;

use Storkplus\SyliusXPayPlugin\DependencyInjection\StorkplusSyliusXPayExtension;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class StorkplusSyliusXPayPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * The convention-derived alias would be "storkplus_sylius_x_pay"; the brand is
     * spelled XPay, so the configuration key is "storkplus_sylius_xpay".
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new StorkplusSyliusXPayExtension();
    }
}
