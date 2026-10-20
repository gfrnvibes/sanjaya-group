<?php

namespace App\Filament\Resources\VariantPricings\Pages;

use App\Filament\Actions\CatalogActions;
use App\Filament\Resources\VariantPricings\VariantPricingResource;
use Filament\Resources\Pages\ManageRecords;

class ManageVariantPricings extends ManageRecords
{
    protected static string $resource = VariantPricingResource::class;

    public function getSubheading(): ?string
    {
        return 'HPP berlaku x (1 + margin), dibulatkan sesuai pengaturan price_rounding. Harga dasar dibuat otomatis setiap resep, pembelian bahan, atau margin berubah; "Perlu update" berarti HPP berubah di luar pemicu tersebut — klik Hitung Ulang.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...CatalogActions::make(withImport: false),
        ];
    }
}
