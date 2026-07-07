<?php

namespace App\Enums;

enum Market: string
{
    case India = 'IN';
    case Us = 'US';

    public function label(): string
    {
        return match ($this) {
            self::India => 'India',
            self::Us => 'United States',
        };
    }
}
