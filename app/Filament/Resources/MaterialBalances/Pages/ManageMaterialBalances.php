<?php

namespace App\Filament\Resources\MaterialBalances\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\MaterialBalances\MaterialBalanceResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMaterialBalances extends ManageRecords
{
    protected static string $resource = MaterialBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
        ];
    }
}
