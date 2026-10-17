<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaterialMovementType: string implements HasLabel
{
    case Opening = 'opening';
    case PurchaseIn = 'purchase_in';
    case ProductionOut = 'production_out';
    case Adjustment = 'adjustment';
    case Opname = 'opname';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opening => 'Stok Awal',
            self::PurchaseIn => 'Pembelian',
            self::ProductionOut => 'Pemakaian Produksi',
            self::Adjustment => 'Penyesuaian',
            self::Opname => 'Stok Opname',
        };
    }
}
