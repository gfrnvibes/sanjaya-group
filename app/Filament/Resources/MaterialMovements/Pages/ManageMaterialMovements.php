<?php

namespace App\Filament\Resources\MaterialMovements\Pages;

use App\Filament\Resources\MaterialMovements\MaterialMovementResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMaterialMovements extends ManageRecords
{
    protected static string $resource = MaterialMovementResource::class;

    public function getSubheading(): ?string
    {
        return 'Ledger append-only. Stok awal / koreksi bahan dicatat dari menu Bahan & HPP > Stok Bahan.';
    }
}
