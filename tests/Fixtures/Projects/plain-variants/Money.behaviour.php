<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class Money
{
    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

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

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

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

    public function format(): string
    {
        $sign = $this->isNegative() ? '-' : '';
        $absolute = abs($this->amount);
        $units = intdiv($absolute, 100);
        $cents = $absolute % 100;

        return sprintf('%s%d.%02d %s', $sign, $units, $cents, $this->currency);
    }

    /**
     * Real behaviour addition for the "structural behaviour change" fixture
     * scenario: flips the sign while keeping the same currency. Not used by
     * any existing test, so applying this variant keeps the whole suite
     * green while still changing Money's token stream and public API.
     */
    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
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
