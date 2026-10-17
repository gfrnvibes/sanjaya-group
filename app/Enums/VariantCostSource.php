<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VariantCostSource: string implements HasLabel
{
    case Bom = 'bom';
    case Legacy = 'legacy';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bom => 'Dari Resep (BOM)',
            self::Legacy => 'HPP Lama',
            self::Manual => 'Manual',
        };
    }
}
