<?php

namespace App\Filament\Resources\TailorPayments\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\TailorPayments\TailorPaymentResource;
use App\Imports\TailorPaymentImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTailorPayments extends ManageRecords
{
    protected static string $resource = TailorPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(TailorPaymentImport::class),
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
        ];
    }
}
