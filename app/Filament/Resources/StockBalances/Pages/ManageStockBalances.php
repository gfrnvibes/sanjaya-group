<?php

namespace App\Filament\Resources\StockBalances\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\StockBalances\StockBalanceResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStockBalances extends ManageRecords
{
    protected static string $resource = StockBalanceResource::class;

    public function getSubheading(): ?string
    {
        return 'Saldo dihitung otomatis dari kartu stok. Ubah stok lewat Barang Masuk, Penyesuaian, Stok Opname, SPK, atau Penjualan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
        ];
    }
}
