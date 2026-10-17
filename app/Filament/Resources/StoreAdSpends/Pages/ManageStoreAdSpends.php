<?php

namespace App\Filament\Resources\StoreAdSpends\Pages;

use App\Filament\Resources\StoreAdSpends\StoreAdSpendResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStoreAdSpends extends ManageRecords
{
    protected static string $resource = StoreAdSpendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
        ];
    }
}
