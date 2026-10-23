<?php

namespace App\Filament\Resources\VariantPriceComponents;

use App\Enums\ProductKind;
use App\Filament\Resources\VariantPriceComponents\Pages\ListProducedProducts;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * PRODUK PRODUKSI: varian model berjenis Produksi — resep (BOM), bahan, ongkos, packing, margin, harga reseller.
 */
class ProducedProductResource extends PriceComponentResource
{
    protected static ?ProductKind $kind = ProductKind::Produksi;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScissors;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Produk Produksi';

    protected static ?string $pluralModelLabel = 'Produk Produksi';

    protected static ?string $slug = 'produk-produksi';

    public static function getPages(): array
    {
        return [
            'index' => ListProducedProducts::route('/'),
        ];
    }
}
