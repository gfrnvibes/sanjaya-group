<?php

namespace App\Filament\Resources\SupplierWholesalePrices\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\SupplierWholesalePrices\SupplierWholesalePriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierWholesalePrices extends ManageRecords
{
    protected static string $resource = SupplierWholesalePriceResource::class;

    public function getSubheading(): ?string
    {
        return 'Contoh: ≥10 pcs Rp13.000, ≥20 pcs Rp12.000. Saat import pesanan, baris dengan QTY ≥ 10 otomatis memakai harga grosir sesuai tingkatnya '
            .'(QTY 15 = harga ≥10, QTY 25 = harga ≥20) sebagai titipan: harga ke reseller = harga supplier. '
            .'Supplier yang dipakai = supplier lot Barang Masuk berikutnya (FIFO).';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
            CreateAction::make(),
        ];
    }
}
