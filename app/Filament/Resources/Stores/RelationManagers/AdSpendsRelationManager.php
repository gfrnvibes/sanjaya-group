<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Resources\StoreAdSpends\StoreAdSpendResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class AdSpendsRelationManager extends RelationManager
{
    protected static string $relationship = 'adSpends';

    protected static ?string $relatedResource = StoreAdSpendResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
            ]);
    }
}
