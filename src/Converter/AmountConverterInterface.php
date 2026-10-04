<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Converter;

interface AmountConverterInterface
{
    public function toMinorUnits(int $syliusAmount, string $currencyCode): int;
}
