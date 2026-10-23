<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kategori alasan baris import gagal (semua jenis data), beserta cara memperbaikinya.
 * Kasus khusus pesanan dipakai SalesOrderImporter; kasus umum (MissingValue ... Conflict) ditebak dari pesan error
 * importer lain lewat classify().
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
    case MissingValue = 'missing_value';
    case InvalidValue = 'invalid_value';
    case NotFound = 'not_found';
    case Conflict = 'conflict';

    /**
     * Tebak kategori dari pesan error importer (pesan berbahasa Indonesia dari SpreadsheetRow / importer).
     */
    public static function classify(string $message): self
    {
        $text = mb_strtolower($message);

        return match (true) {
            str_contains($text, 'header') || str_contains($text, 'format file') => self::FileFormat,
            str_contains($text, 'sku') && str_contains($text, 'tidak ditemukan') => self::SkuNotFound,
            str_contains($text, 'toko') && str_contains($text, 'tidak ditemukan') => self::StoreNotFound,
            str_contains($text, 'tidak ditemukan') || str_contains($text, 'belum ada') || str_contains($text, 'belum terdaftar') => self::NotFound,
            str_contains($text, 'tanggal') && (str_contains($text, 'format') || str_contains($text, 'tidak valid') || str_contains($text, 'kosong')) => self::InvalidDate,
            str_contains($text, 'kosong') || str_contains($text, 'wajib') => self::MissingValue,
            str_contains($text, 'sudah dipakai') || str_contains($text, 'sudah dipetakan') || str_contains($text, 'sudah dihapus') || str_contains($text, 'milik') || str_contains($text, 'sudah ada') => self::Conflict,
            str_contains($text, 'harga') && str_contains($text, 'belum') => self::PriceMissing,
            str_contains($text, 'harus') || str_contains($text, 'maksimal') || str_contains($text, 'tidak valid') => self::InvalidValue,
            str_contains($text, 'gagal disimpan') || str_contains($text, 'kesalahan') => self::Database,
            default => self::BusinessRule,
        };
    }

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
            self::MissingValue => 'Isian wajib kosong',
            self::InvalidValue => 'Isian tidak valid',
            self::NotFound => 'Data acuan tidak ditemukan',
            self::Conflict => 'Bentrok dengan data lain',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::StoreNotFound, self::SkuNotFound, self::NotFound => 'danger',
            self::Conflict => 'warning',
            self::MissingValue, self::InvalidValue => 'info',
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
            self::SkuNotFound => 'Klik Petakan SKU: sistem menyarankan varian dari nama produk, warna & ukuran. Pemetaan disimpan dan semua baris dengan SKU yang sama ikut dicoba ulang.',
            self::SkuInactive => 'Aktifkan varian di menu Varian bila masih dijual, atau ganti ke SKU penggantinya.',
            self::InvalidQty => 'Isi QTY dengan bilangan bulat minimal 1.',
            self::InvalidRevenue => 'Isi PENDAPATAN TOKO dengan angka >= 0, atau kosongkan.',
            self::PriceMissing => 'Lengkapi resep (BOM) dan harga bahan varian ini agar harga reseller terbentuk mulai tanggal pesanan, lalu import ulang.',
            self::BusinessRule => 'Baca pesan alasan, perbaiki data terkait, lalu import ulang.',
            self::Database => 'Kesalahan teknis saat menyimpan. Coba import ulang; bila tetap gagal hubungi admin sistem.',
            self::MissingValue => 'Isi kolom yang disebut di alasan (klik Edit pada baris), lalu simpan untuk mencoba ulang.',
            self::InvalidValue => 'Perbaiki isian sesuai alasan (angka, Ya/Tidak, pilihan yang tersedia, panjang maksimal) lewat Edit, atau lewat template kategori ini.',
            self::NotFound => 'Data acuan (gudang, varian, bahan, pemilik, tier, dll.) belum ada: daftarkan dulu di menunya, atau ganti isian ke data yang sudah ada.',
            self::Conflict => 'Data ini bentrok dengan data lain yang sudah ada (kode/nama/SKU dipakai). Ganti isian atau perbaiki data lainnya terlebih dahulu.',
        };
    }
}
