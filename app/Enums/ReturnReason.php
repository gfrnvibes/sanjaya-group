<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Alasan retur penjualan. Cacat produksi (jahit/bahan) = bahan perbaikan kualitas konveksi.
 */
enum ReturnReason: string implements HasColor, HasLabel
{
    case CacatJahit = 'cacat_jahit';
    case CacatBahan = 'cacat_bahan';
    case SalahUkuran = 'salah_ukuran';
    case SalahKirim = 'salah_kirim';
    case TidakSesuai = 'tidak_sesuai';
    case RusakKirim = 'rusak_kirim';
    case BerubahPikiran = 'berubah_pikiran';
    case Lainnya = 'lainnya';

    public function getLabel(): string
    {
        return match ($this) {
            self::CacatJahit => 'Cacat jahitan',
            self::CacatBahan => 'Cacat bahan',
            self::SalahUkuran => 'Ukuran tidak sesuai',
            self::SalahKirim => 'Salah kirim (SKU/warna)',
            self::TidakSesuai => 'Tidak sesuai foto/deskripsi',
            self::RusakKirim => 'Rusak saat pengiriman',
            self::BerubahPikiran => 'Pembeli berubah pikiran',
            self::Lainnya => 'Lainnya',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CacatJahit, self::CacatBahan => 'danger',
            self::SalahUkuran, self::TidakSesuai => 'warning',
            self::SalahKirim, self::RusakKirim => 'info',
            default => 'gray',
        };
    }
}
