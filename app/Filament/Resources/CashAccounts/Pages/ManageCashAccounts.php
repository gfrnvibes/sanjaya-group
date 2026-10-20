<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Imports\CashAccountImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCashAccounts extends ManageRecords
{
    protected static string $resource = CashAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(CashAccountImport::class),
            CreateAction::make(),
        ];
    }
}
