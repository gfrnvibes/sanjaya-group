<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductKind: string implements HasLabel
{
    case Produksi = 'produksi';
    case BeliJadi = 'beli_jadi';
    case Jasa = 'jasa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Produksi => 'Produksi',
            self::BeliJadi => 'Beli Jadi',
            self::Jasa => 'Jasa',
        };
    }
}
