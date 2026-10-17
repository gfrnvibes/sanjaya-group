<?php

namespace App\Filament\Resources\ResellerPriceHistories\Pages;

use App\Filament\Resources\ResellerPriceHistories\ResellerPriceHistoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageResellerPriceHistories extends ManageRecords
{
    protected static string $resource = ResellerPriceHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'approved_by' => auth()->id()]),
        ];
    }
}
