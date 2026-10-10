<?php

declare(strict_types=1);

namespace App\Forms\Fields;

use TranquilTools\FormBuilder\Fields\BaseField;

class Heading extends BaseField
{
    protected string $type = 'heading';

    public static function make(?string $name = null): static
    {
        $instance = parent::make();
        $instance->label = $name;
        $instance->attributes['fullWidth'] = true;

        return $instance;
    }
}
