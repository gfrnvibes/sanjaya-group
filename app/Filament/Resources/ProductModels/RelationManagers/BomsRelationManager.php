<?php

namespace App\Filament\Resources\ProductModels\RelationManagers;

use App\Filament\Resources\Boms\BomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class BomsRelationManager extends RelationManager
{
    protected static string $relationship = 'boms';

    protected static ?string $relatedResource = BomResource::class;

    protected static ?string $title = 'Resep (BOM)';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
