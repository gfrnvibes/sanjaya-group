<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\SkuAliases\SkuAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SkuAliasesRelationManager extends RelationManager
{
    protected static string $relationship = 'skuAliases';

    protected static ?string $relatedResource = SkuAliasResource::class;

    protected static ?string $title = 'Pemetaan SKU';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
