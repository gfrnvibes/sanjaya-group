<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kategori alasan baris import pesanan gagal, beserta cara memperbaikinya.
 */
enum ImportFailureReason: string implements HasColor, HasLabel
{
    case FileFormat = 'file_format';
    case InvalidDate = 'invalid_date';
    case StoreNotFound = 'store_not_found';
    case StoreInactive = 'store_inactive';
    case SkuNotFound = 'sku_not_found';
    case SkuInactive = 'sku_inactive';
    case InvalidQty = 'invalid_qty';
    case InvalidRevenue = 'invalid_revenue';
    case PriceMissing = 'price_missing';
    case BusinessRule = 'business_rule';
    case Database = 'database';

    public function getLabel(): string
    {
        return match ($this) {
            self::FileFormat => 'Format file',
            self::InvalidDate => 'Tanggal tidak valid',
            self::StoreNotFound => 'Toko tidak ditemukan',
            self::StoreInactive => 'Toko nonaktif',
            self::SkuNotFound => 'SKU tidak ditemukan',
            self::SkuInactive => 'SKU nonaktif',
            self::InvalidQty => 'QTY tidak valid',
            self::InvalidRevenue => 'Pendapatan tidak valid',
            self::PriceMissing => 'Harga reseller belum ada',
            self::BusinessRule => 'Aturan sistem',
            self::Database => 'Gagal disimpan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::StoreNotFound, self::SkuNotFound => 'danger',
            self::PriceMissing, self::StoreInactive, self::SkuInactive => 'warning',
            self::InvalidDate, self::InvalidQty, self::InvalidRevenue => 'info',
            default => 'gray',
        };
    }

    /** Langkah perbaikan yang ditampilkan di layar dan di file baris gagal. */
    public function hint(): string
    {
        return match ($this) {
            self::FileFormat => 'Gunakan template import pesanan: pastikan ada header TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, QTY.',
            self::InvalidDate => 'Isi TANGGAL dengan format tanggal Excel atau teks YYYY-MM-DD / DD/MM/YYYY.',
            self::StoreNotFound => 'Samakan NAMA TOKO dengan nama di menu Toko (cek salah ketik/spasi), atau daftarkan tokonya dulu lewat import Reseller & Toko.',
            self::StoreInactive => 'Aktifkan toko di menu Toko bila masih berjualan, atau abaikan baris ini.',
            self::SkuNotFound => 'Perbaiki SKU VARIAN sesuai master varian, atau tambahkan SKU BIG SELLER ini di menu Pemetaan SKU.',
            self::SkuInactive => 'Aktifkan varian di menu Varian bila masih dijual, atau ganti ke SKU penggantinya.',
            self::InvalidQty => 'Isi QTY dengan bilangan bulat minimal 1.',
            self::InvalidRevenue => 'Isi PENDAPATAN TOKO dengan angka >= 0, atau kosongkan.',
            self::PriceMissing => 'Lengkapi resep (BOM) dan harga bahan varian ini agar harga reseller terbentuk mulai tanggal pesanan, lalu import ulang.',
            self::BusinessRule => 'Baca pesan alasan, perbaiki data terkait, lalu import ulang.',
            self::Database => 'Kesalahan teknis saat menyimpan. Coba import ulang; bila tetap gagal hubungi admin sistem.',
        };
    }
}
