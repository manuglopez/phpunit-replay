<?php

declare(strict_types=1);

namespace App;

final class Greeter
{
    public function greet(string $name, string $timeOfDay = 'day'): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            $trimmed = 'stranger';
        }

        $salutation = match ($timeOfDay) {
            'morning' => 'Good morning',
            'afternoon' => 'Good afternoon',
            'evening' => 'Good evening',
            default => 'Hello',
        };

        return sprintf('%s, %s!', $salutation, $trimmed);
    }

    public function shout(string $name): string
    {
        return strtoupper($this->greet($name));
    }
}
