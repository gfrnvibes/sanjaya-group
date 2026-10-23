<?php

namespace App\Filament\Resources\VariantPriceComponents;

use App\Enums\ProductKind;
use App\Filament\Resources\VariantPriceComponents\Pages\ListReadyMadeProducts;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * PRODUK BELI JADI: varian model berjenis Beli Jadi — supplier, harga beli, packing, harga grosir, margin, harga reseller.
 */
class ReadyMadeProductResource extends PriceComponentResource
{
    protected static ?ProductKind $kind = ProductKind::BeliJadi;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Produk Beli Jadi';

    protected static ?string $pluralModelLabel = 'Produk Beli Jadi';

    protected static ?string $slug = 'produk-beli-jadi';

    public static function getPages(): array
    {
        return [
            'index' => ListReadyMadeProducts::route('/'),
        ];
    }
}
