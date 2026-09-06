<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cart;
use App\Money;
use App\TaxCalculator;
use InvalidArgumentException;

final class CartTest extends TestCase
{
    public function testEmptyCartHasZeroTotals(): void
    {
        $cart = new Cart(TaxCalculator::standard());

        self::assertTrue($cart->isEmpty());
        self::assertSame(0, $cart->itemCount());
        self::assertTrue($cart->subtotal()->isZero());
        self::assertTrue($cart->total()->isZero());
    }

    public function testAddingItemsAccumulatesSubtotalAndTax(): void
    {
        $cart = new Cart(TaxCalculator::standard());

        $cart->addItem('Widget', Money::fromCents(1000, 'EUR'), 2);
        $cart->addItem('Gadget', Money::fromCents(500, 'EUR'));

        self::assertFalse($cart->isEmpty());
        self::assertSame(3, $cart->itemCount());
        self::assertSame(2500, $cart->subtotal()->amount);
        self::assertSame(525, $cart->taxTotal()->amount);
        self::assertSame(3025, $cart->total()->amount);
    }

    public function testExemptCalculatorProducesNoTax(): void
    {
        $cart = new Cart(TaxCalculator::exempt());
        $cart->addItem('Book', Money::fromCents(2000, 'EUR'));

        self::assertTrue($cart->taxTotal()->isZero());
        self::assertSame($cart->subtotal()->amount, $cart->total()->amount);
    }

    public function testRejectsEmptyItemName(): void
    {
        $cart = new Cart(TaxCalculator::standard());

        $this->expectException(InvalidArgumentException::class);

        $cart->addItem('', Money::fromCents(100, 'EUR'));
    }

    public function testRejectsMismatchedCurrency(): void
    {
        $cart = new Cart(TaxCalculator::standard(), 'EUR');

        $this->expectException(InvalidArgumentException::class);

        $cart->addItem('Import', Money::fromCents(100, 'USD'));
    }

    public function testRejectsInvalidQuantity(): void
    {
        $cart = new Cart(TaxCalculator::standard());

        $this->expectException(InvalidArgumentException::class);

        $cart->addItem('Widget', Money::fromCents(100, 'EUR'), 0);
    }
}
