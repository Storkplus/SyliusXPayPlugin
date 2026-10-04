<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Converter;

/**
 * Sylius stores every amount as an integer with two implied decimals, whatever the
 * currency. XPay wants the currency's real minor unit, which is three decimals for
 * the Gulf / Levant dinars and rials.
 */
final class AmountConverter implements AmountConverterInterface
{
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'LYD', 'OMR'];

    public function toMinorUnits(int $syliusAmount, string $currencyCode): int
    {
        if (in_array(strtoupper($currencyCode), self::THREE_DECIMAL_CURRENCIES, true)) {
            return $syliusAmount * 10;
        }

        return $syliusAmount;
    }
}
