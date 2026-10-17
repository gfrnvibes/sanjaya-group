<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SalesImportSource: string implements HasLabel
{
    case BigSeller = 'bigseller';
    case Shopee = 'shopee';
    case Tiktok = 'tiktok';
    case Manual = 'manual';
    case Website = 'website';

    public function getLabel(): string
    {
        return match ($this) {
            self::BigSeller => 'BigSeller',
            self::Shopee => 'Shopee',
            self::Tiktok => 'TikTok',
            self::Manual => 'Manual',
            self::Website => 'Website',
        };
    }
}
