<?php

namespace App\Filament\Resources\VariantPriceComponents\Pages;

use App\Filament\Actions\PrintPriceListAction;
use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\VariantPriceComponents\PriceComponentResource;
use App\Models\Setting;
use Filament\Resources\Pages\ListRecords;

class ListPriceComponents extends ListRecords
{
    protected static string $resource = PriceComponentResource::class;

    protected static function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value * 100, 2, ',', '.'), '0'), ',').'%';
    }

    public function getSubheading(): ?string
    {
        return 'HPP, margin, harga dasar & harga reseller per tier yang berlaku hari ini (semua produk). '
            .'Default margin: produksi '.self::percent(Setting::valueOf('margin_default', 0.30))
            .', beli jadi '.self::percent(Setting::valueOf('margin_ready_made_pct', 0.05))
            .'; packing beli jadi Rp'.number_format((float) Setting::valueOf('packing_cost_default', 2000), 0, ',', '.')
            .', produksi Rp'.number_format((float) Setting::valueOf('packing_cost_default_production', 0), 0, ',', '.').' (ubah di Pengaturan). '
            .'Ubah harga (dengan tanggal berlaku): margin & packing; beli jadi juga harga dasar manual. Pesanan lama tidak berubah.';
    }

    protected function getHeaderActions(): array
    {
        return [
            PrintPriceListAction::make(),
            ...ResourceIo::make(),
        ];
    }
}
