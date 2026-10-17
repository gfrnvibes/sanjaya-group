<?php

namespace App\Filament\Resources\Owners\RelationManagers;

use App\Filament\Resources\Resellers\ResellerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ResellersRelationManager extends RelationManager
{
    protected static string $relationship = 'resellers';

    protected static ?string $relatedResource = ResellerResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
