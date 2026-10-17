<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BomColorMode: string implements HasLabel
{
    case FollowVariant = 'follow_variant';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::FollowVariant => 'Ikut Warna Produk',
            self::Fixed => 'Bahan Tetap',
        };
    }
}
