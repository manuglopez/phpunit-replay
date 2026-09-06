<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class Cart
{
    /** @var list<array{name:string, price:Money, quantity:int}> */
    private array $items = [];

    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly string $currency = 'EUR',
    ) {
    }

    public function addItem(string $name, Money $price, int $quantity = 1): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Item name cannot be empty.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        if ($price->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf(
                'Item currency %s does not match cart currency %s.',
                $price->currency,
                $this->currency,
            ));
        }

        $this->items[] = ['name' => $name, 'price' => $price, 'quantity' => $quantity];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function itemCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            $count += $item['quantity'];
        }

        return $count;
    }

    public function subtotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->items as $item) {
            $total = $total->add($item['price']->multiply((float) $item['quantity']));
        }

        return $total;
    }

    public function taxTotal(): Money
    {
        return $this->tax->taxFor($this->subtotal());
    }

    public function total(): Money
    {
        return $this->tax->grossFor($this->subtotal());
    }
}
