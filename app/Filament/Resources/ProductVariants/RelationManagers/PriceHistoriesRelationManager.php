<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\ResellerPriceHistories\ResellerPriceHistoryResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Riwayat harga dasar (otomatis dari resep + margin; tidak diisi manual).
 */
class PriceHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistories';

    protected static ?string $relatedResource = ResellerPriceHistoryResource::class;

    protected static ?string $title = 'Riwayat Harga Reseller';

    public function table(Table $table): Table
    {
        return $table;
    }
}
