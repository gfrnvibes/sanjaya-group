<?php

namespace App\Filament\Resources\StoreMonthlyProfits\Pages;

use App\Filament\Resources\StoreMonthlyProfits\StoreMonthlyProfitResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStoreMonthlyProfits extends ManageRecords
{
    protected static string $resource = StoreMonthlyProfitResource::class;

    public function getSubheading(): ?string
    {
        return 'Fee admin memakai potongan aktual pesanan bila ada, selain itu estimasi % channel pada tanggal pesanan. Iklan memakai biaya iklan aktual bila diisi.';
    }
}
