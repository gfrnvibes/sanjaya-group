<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Jenis gudang: menentukan stok apa yang boleh disimpan (dijaga di StockLedger).
 */
enum WarehouseKind: string implements HasColor, HasLabel
{
    case Bahan = 'bahan';
    case BarangJadi = 'barang_jadi';
    case Campuran = 'campuran';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bahan => 'Bahan Baku',
            self::BarangJadi => 'Barang Jadi',
            self::Campuran => 'Campuran',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bahan => 'warning',
            self::BarangJadi => 'success',
            self::Campuran => 'gray',
        };
    }

    public function storesGoods(): bool
    {
        return $this !== self::Bahan;
    }

    public function storesMaterials(): bool
    {
        return $this !== self::BarangJadi;
    }
}
