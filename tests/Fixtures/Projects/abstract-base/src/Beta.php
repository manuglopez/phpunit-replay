<?php

declare(strict_types=1);

namespace App;

final class Beta
{
    public static function compute(int $value): int
    {
        return $value + 10;
    }
}
