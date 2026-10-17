<?php

namespace App\Filament\Resources\MaterialPurchases\Pages;

use App\Filament\Resources\MaterialPurchases\MaterialPurchaseResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMaterialPurchase extends ViewRecord
{
    protected static string $resource = MaterialPurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
