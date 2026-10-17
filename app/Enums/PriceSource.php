<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PriceSource: string implements HasLabel
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Import = 'import';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => 'Otomatis',
            self::Manual => 'Manual',
            self::Import => 'Impor',
        };
    }
}
