<?php

namespace App\Filament\Resources\ResellerPriceHistories\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ResellerPriceHistories\ResellerPriceHistoryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageResellerPriceHistories extends ManageRecords
{
    protected static string $resource = ResellerPriceHistoryResource::class;

    public function getSubheading(): ?string
    {
        return 'Harga dasar dibuat otomatis dari HPP resep x (1 + margin) setiap resep, pembelian bahan, atau margin berubah.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
        ];
    }
}
