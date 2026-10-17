<?php

namespace App\Filament\Resources\VariantPricings\Pages;

use App\Filament\Resources\VariantPricings\VariantPricingResource;
use Filament\Resources\Pages\ManageRecords;

class ManageVariantPricings extends ManageRecords
{
    protected static string $resource = VariantPricingResource::class;

    public function getSubheading(): ?string
    {
        return 'HPP berlaku x (1 + margin), dibulatkan sesuai pengaturan price_rounding. Setujui untuk menjadikannya harga dasar reseller baru.';
    }
}
