<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\VariantCosts\VariantCostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    protected static ?string $relatedResource = VariantCostResource::class;

    protected static ?string $title = 'Riwayat HPP';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
