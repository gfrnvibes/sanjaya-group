<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\ResellerPriceHistories\ResellerPriceHistoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class PriceHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistories';

    protected static ?string $relatedResource = ResellerPriceHistoryResource::class;

    protected static ?string $title = 'Riwayat Harga Reseller';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'approved_by' => auth()->id()]),
            ]);
    }
}
