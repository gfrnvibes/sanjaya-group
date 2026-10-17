<?php

namespace App\Filament\Resources\Tailors\Pages;

use App\Filament\Resources\Tailors\TailorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTailor extends ViewRecord
{
    protected static string $resource = TailorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
