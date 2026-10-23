<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Sumber data impor pesanan. Tiap sumber punya format file sendiri:
 *  - BigSeller: export pesanan BigSeller (Nomor Pesanan, Nama Panggilan Toko BigSeller, Waktu Pesanan Dibuat, ...);
 *  - Website: template dengan NO PESANAN, NAMA PRODUK, SKU, NAMA VARIASI;
 *  - Manual / Shopee / TikTok / Lazada: template tanpa nomor pesanan (nomor dibuat otomatis).
 */
enum SalesImportSource: string implements HasLabel
{
    case BigSeller = 'bigseller';
    case Website = 'website';
    case Shopee = 'shopee';
    case Tiktok = 'tiktok';
    case Lazada = 'lazada';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::BigSeller => 'BigSeller',
            self::Website => 'Website',
            self::Shopee => 'Shopee',
            self::Tiktok => 'TikTok',
            self::Lazada => 'Lazada',
            self::Manual => 'Manual',
        };
    }

    /** Nomor pesanan diambil dari file (true) atau dibuat otomatis (false). */
    public function hasOrderNumber(): bool
    {
        return in_array($this, [self::BigSeller, self::Website], true);
    }

    /** Ringkasan kolom file untuk form impor. */
    public function formatHint(): string
    {
        return match ($this) {
            self::BigSeller => 'File export pesanan BigSeller apa adanya: Nomor Pesanan, Nama Panggilan Toko BigSeller, Waktu Pesanan Dibuat, Nama Produk, SKU, Nama Variasi, Jumlah, Harga Satuan, Subtotal Produk. 1 baris = 1 produk; nomor pesanan diambil dari file.',
            self::Website => 'Template Website: TANGGAL, NAMA TOKO, NO PESANAN, NAMA PRODUK, SKU, NAMA VARIASI, QTY, PENDAPATAN TOKO. Nomor pesanan diambil dari file.',
            default => 'Template '.$this->getLabel().': TANGGAL, NAMA TOKO, SKU VARIAN atau SKU TOKO, NAMA PRODUK, NAMA VARIASI, QTY, PENDAPATAN TOKO. Nomor pesanan dibuat otomatis.',
        };
    }
}
