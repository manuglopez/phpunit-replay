<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class Discount
{
    private function __construct(
        private readonly float $percentage,
        private readonly int $flatCents,
    ) {
    }

    public static function percentage(float $percentage): self
    {
        if ($percentage < 0.0 || $percentage > 100.0) {
            throw new InvalidArgumentException('Percentage must be between 0 and 100.');
        }

        return new self($percentage, 0);
    }

    public static function flat(int $cents): self
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Flat discount cannot be negative.');
        }

        return new self(0.0, $cents);
    }

    public function apply(Money $price): Money
    {
        if ($this->percentage > 0.0) {
            $discounted = $price->multiply(1.0 - ($this->percentage / 100.0));

            return $discounted->isNegative() ? Money::zero($price->currency) : $discounted;
        }

        $discounted = $price->subtract(Money::fromCents($this->flatCents, $price->currency));

        return $discounted->isNegative() ? Money::zero($price->currency) : $discounted;
    }

    public function isFree(Money $price): bool
    {
        return $this->apply($price)->isZero();
    }
}
