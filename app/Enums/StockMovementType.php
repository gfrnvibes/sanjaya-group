<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasLabel
{
    case Opening = 'opening';
    case ProductionIn = 'production_in';
    case GoodsIn = 'goods_in';
    case ReturnIn = 'return_in';
    case SaleOut = 'sale_out';
    case SaleCancel = 'sale_cancel';
    case Adjustment = 'adjustment';
    case Opname = 'opname';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opening => 'Stok Awal',
            self::ProductionIn => 'Hasil Produksi',
            self::GoodsIn => 'Barang Masuk',
            self::ReturnIn => 'Retur Masuk',
            self::SaleOut => 'Penjualan',
            self::SaleCancel => 'Batal Jual',
            self::Adjustment => 'Penyesuaian',
            self::Opname => 'Stok Opname',
        };
    }
}
