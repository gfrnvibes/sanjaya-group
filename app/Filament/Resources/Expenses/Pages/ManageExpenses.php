<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Imports\ExpenseImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenses extends ManageRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(ExpenseImport::class),
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
        ];
    }
}
