<?php

declare(strict_types=1);

namespace App;

final class Other
{
    public function half(int $value): int
    {
        return intdiv($value, 2);
    }
}
