<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

// Immutable value object for an amount of money in integer minor units
// (cents) plus an ISO-ish currency code. This variant only rewords the
// comments and docblocks below; the token stream is identical to
// src/Money.php.
final class Money
{
    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    // Build from an integer amount of cents; currency is upper-cased.
    public static function fromCents(int $amount, string $currency = 'EUR'): self
    {
        if ($currency === '') {
            throw new InvalidArgumentException('Currency code cannot be empty.');
        }

        return new self($amount, strtoupper($currency));
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return self::fromCents(0, $currency);
    }

    /* Addition requires matching currencies. */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    /* Subtraction requires matching currencies. */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    // Scales the amount, rounding half away from zero to the nearest cent.
    public function multiply(float $factor): self
    {
        return new self((int) round($this->amount * $factor), $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    // Renders as "-1.50 EUR" style text.
    public function format(): string
    {
        $sign = $this->isNegative() ? '-' : '';
        $absolute = abs($this->amount);
        $units = intdiv($absolute, 100);
        $cents = $absolute % 100;

        return sprintf('%s%d.%02d %s', $sign, $units, $cents, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Currency mismatch: %s vs %s.',
                $this->currency,
                $other->currency,
            ));
        }
    }
}
