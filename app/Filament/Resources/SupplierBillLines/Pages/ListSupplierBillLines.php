<?php

namespace App\Filament\Resources\SupplierBillLines\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Pages\SupplierBills;
use App\Filament\Resources\SupplierBillLines\SupplierBillLineResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSupplierBillLines extends ListRecords
{
    protected static string $resource = SupplierBillLineResource::class;

    public function getSubheading(): ?string
    {
        return 'Per baris pesanan: supplier diambil dari lot Barang Masuk paling lama (FIFO). Qty negatif = retur yang kembali ke stok.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Kembali ke Tagihan Supplier')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(SupplierBills::getUrl()),
            ...ResourceIo::make(),
        ];
    }
}
