<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GoodsReceiptSource: string implements HasLabel
{
    case Produksi = 'produksi';
    case Borongan = 'borongan';
    case BeliJadi = 'beli_jadi';
    case Retur = 'retur';
    case Lainnya = 'lainnya';

    public function getLabel(): string
    {
        return match ($this) {
            self::Produksi => 'Produksi',
            self::Borongan => 'Borongan',
            self::BeliJadi => 'Beli Jadi',
            self::Retur => 'Retur',
            self::Lainnya => 'Lainnya',
        };
    }
}
