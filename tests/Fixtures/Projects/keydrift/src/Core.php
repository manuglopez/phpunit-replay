<?php

declare(strict_types=1);

namespace App;

final class Core
{
    public function double(int $value): int
    {
        return $value * 2;
    }
}
