<?php

declare(strict_types=1);

namespace App\Traits;

trait GenerateRandomHexColor
{
    public function generateRandomHexColor(): string
    {
        return sprintf('#%06x', random_int(0, 0xFFFFFF));
    }
}
