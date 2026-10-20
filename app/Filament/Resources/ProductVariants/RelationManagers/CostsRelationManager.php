<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\VariantCosts\VariantCostResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Riwayat HPP (dihitung dari resep; tidak diisi manual).
 */
class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    protected static ?string $relatedResource = VariantCostResource::class;

    protected static ?string $title = 'Riwayat HPP';

    public function table(Table $table): Table
    {
        return $table;
    }
}
