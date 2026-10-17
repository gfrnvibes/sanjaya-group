<?php

namespace App\Filament\Resources\MaterialPurchases\Pages;

use App\Filament\Resources\MaterialPurchases\MaterialPurchaseResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMaterialPurchase extends EditRecord
{
    protected static string $resource = MaterialPurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
