<?php

namespace App\Filament\Resources\SalesReturns\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Imports\SalesReturnImport;
use Filament\Resources\Pages\ManageRecords;

class ManageSalesReturns extends ManageRecords
{
    protected static string $resource = SalesReturnResource::class;

    public function getSubheading(): ?string
    {
        return 'Catat retur dari halaman Pesanan > Produk > Retur.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(SalesReturnImport::class),
        ];
    }
}
