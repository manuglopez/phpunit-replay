<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class TaxCalculator
{
    public const STANDARD_RATE = 0.21;
    public const REDUCED_RATE = 0.10;
    public const ZERO_RATE = 0.0;

    public function __construct(
        private readonly float $rate = self::STANDARD_RATE,
    ) {
        if ($rate < 0.0 || $rate > 1.0) {
            throw new InvalidArgumentException('Tax rate must be between 0 and 1.');
        }
    }

    public static function standard(): self
    {
        return new self(self::STANDARD_RATE);
    }

    public static function reduced(): self
    {
        return new self(self::REDUCED_RATE);
    }

    public static function exempt(): self
    {
        return new self(self::ZERO_RATE);
    }

    public function taxFor(Money $net): Money
    {
        if ($this->rate === self::ZERO_RATE || $net->isZero()) {
            return Money::zero($net->currency);
        }

        return $net->multiply($this->rate);
    }

    public function grossFor(Money $net): Money
    {
        return $net->add($this->taxFor($net));
    }

    public function rate(): float
    {
        return $this->rate;
    }
}
