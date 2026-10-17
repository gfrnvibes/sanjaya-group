<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockAdjustmentReason: string implements HasLabel
{
    case Rusak = 'rusak';
    case Sampel = 'sampel';
    case Custom = 'custom';
    case Koreksi = 'koreksi';
    case Lainnya = 'lainnya';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rusak => 'Rusak',
            self::Sampel => 'Sampel',
            self::Custom => 'Custom',
            self::Koreksi => 'Koreksi',
            self::Lainnya => 'Lainnya',
        };
    }
}
