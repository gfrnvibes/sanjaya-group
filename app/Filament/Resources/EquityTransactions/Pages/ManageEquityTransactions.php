<?php

namespace App\Filament\Resources\EquityTransactions\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\EquityTransactions\EquityTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEquityTransactions extends ManageRecords
{
    protected static string $resource = EquityTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
        ];
    }
}
