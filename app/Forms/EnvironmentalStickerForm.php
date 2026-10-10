<?php

declare(strict_types=1);

namespace App\Forms;

class EnvironmentalStickerForm extends VignetteForm
{
    protected function heading(): string
    {
        return __('Environmental sticker');
    }
}
