<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money;
use App\TaxCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    #[DataProvider('rates')]
    public function testTaxForAppliesExpectedRate(TaxCalculator $calculator, int $netCents, int $expectedTaxCents): void
    {
        $net = Money::fromCents($netCents, 'EUR');

        $tax = $calculator->taxFor($net);

        self::assertSame($expectedTaxCents, $tax->amount);
        self::assertSame('EUR', $tax->currency);
    }

    #[DataProvider('rates')]
    public function testGrossForAddsTaxToNet(TaxCalculator $calculator, int $netCents, int $expectedTaxCents): void
    {
        $net = Money::fromCents($netCents, 'EUR');

        $gross = $calculator->grossFor($net);

        self::assertSame($netCents + $expectedTaxCents, $gross->amount);
    }

    public function testExemptRateNeverTaxesAnything(): void
    {
        $calculator = TaxCalculator::exempt();

        self::assertSame(0.0, $calculator->rate());
        self::assertSame(0, $calculator->taxFor(Money::fromCents(5000, 'EUR'))->amount);
    }

    public function testInvalidRateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxCalculator(1.5);
    }

    /**
     * @return array<string, array{0: TaxCalculator, 1: int, 2: int}>
     */
    public static function rates(): array
    {
        return [
            'standard on 1000 cents' => [TaxCalculator::standard(), 1000, 210],
            'reduced on 1000 cents' => [TaxCalculator::reduced(), 1000, 100],
            'exempt on 1000 cents' => [TaxCalculator::exempt(), 1000, 0],
            'standard on zero is zero' => [TaxCalculator::standard(), 0, 0],
        ];
    }
}
