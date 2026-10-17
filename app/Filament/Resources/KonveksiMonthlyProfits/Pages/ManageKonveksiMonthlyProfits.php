<?php

namespace App\Filament\Resources\KonveksiMonthlyProfits\Pages;

use App\Filament\Resources\KonveksiMonthlyProfits\KonveksiMonthlyProfitResource;
use Filament\Resources\Pages\ManageRecords;

class ManageKonveksiMonthlyProfits extends ManageRecords
{
    protected static string $resource = KonveksiMonthlyProfitResource::class;

    public function getSubheading(): ?string
    {
        return 'Penjualan = tagihan ke reseller (status selain batal) dikurangi kredit retur; HPP dari snapshot transaksi.';
    }
}
