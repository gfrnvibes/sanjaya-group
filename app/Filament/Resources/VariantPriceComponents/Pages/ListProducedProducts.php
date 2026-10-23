<?php

namespace App\Filament\Resources\VariantPriceComponents\Pages;

use App\Filament\Resources\VariantPriceComponents\ProducedProductResource;
use App\Models\Setting;

class ListProducedProducts extends ListPriceComponents
{
    protected static string $resource = ProducedProductResource::class;

    public function getSubheading(): ?string
    {
        return 'Produk hasil produksi: HPP hanya dari resep (BOM) = bahan (x harga beli bahan terakhir) + ongkos, ditambah packing. '
            .'Harga naik otomatis saat ada pembelian bahan baku dengan harga baru (berlaku mulai tanggal beli). '
            .'Margin default '.self::percent(Setting::valueOf('margin_default', 0.30)).'. '
            .'Yang bisa diubah hanya margin & packing (Ubah harga, dengan tanggal berlaku); harga dasar dihitung otomatis, pesanan lama tidak berubah.';
    }
}
