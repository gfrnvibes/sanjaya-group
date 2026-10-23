<?php

namespace App\Filament\Resources\VariantPriceComponents\Pages;

use App\Filament\Resources\VariantPriceComponents\ReadyMadeProductResource;
use App\Models\Setting;

class ListReadyMadeProducts extends ListPriceComponents
{
    protected static string $resource = ReadyMadeProductResource::class;

    public function getSubheading(): ?string
    {
        return 'Produk beli jadi: HPP = harga beli terakhir (Barang Masuk sumber Beli Jadi) + packing. '
            .'Harga naik otomatis saat Barang Masuk dengan harga beli baru (berlaku mulai tanggal terima). '
            .'Margin default '.self::percent(Setting::valueOf('margin_ready_made_pct', 0.05))
            .', packing default Rp'.number_format((float) Setting::valueOf('packing_cost_default', 3000), 0, ',', '.').'. '
            .'Ubah harga: margin, packing, atau harga dasar manual — dengan tanggal berlaku; pesanan lama tidak berubah.';
    }
}
