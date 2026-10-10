<?php

declare(strict_types=1);

namespace App\Contracts;

interface HasColor
{
    public function getColor(): string|array|null;
}
