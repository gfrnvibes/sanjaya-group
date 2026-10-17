<?php

namespace App\Filament\Resources\Tailors\Pages;

use App\Filament\Resources\Tailors\TailorResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTailor extends EditRecord
{
    protected static string $resource = TailorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
