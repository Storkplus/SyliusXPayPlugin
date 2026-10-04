<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Converter;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\Converter\AmountConverter;

final class AmountConverterTest extends TestCase
{
    public function test_two_decimal_currencies_are_passed_through(): void
    {
        self::assertSame(149900, (new AmountConverter())->toMinorUnits(149900, 'EGP'));
        self::assertSame(1050, (new AmountConverter())->toMinorUnits(1050, 'usd'));
    }

    public function test_three_decimal_currencies_are_scaled(): void
    {
        foreach (['KWD', 'BHD', 'JOD', 'OMR', 'LYD'] as $currency) {
            self::assertSame(12340, (new AmountConverter())->toMinorUnits(1234, $currency), $currency);
        }
    }
}
