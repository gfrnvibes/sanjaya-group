<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportFailureStatus: string implements HasColor, HasLabel
{
    /** Belum diperbaiki. */
    case Open = 'open';

    /** Sudah diimport ulang dan tersimpan (atau ternyata pesanannya sudah ada). */
    case Fixed = 'fixed';

    /** Sudah diimport ulang tapi gagal lagi: lihat baris gagal pada batch yang lebih baru. */
    case Retried = 'retried';

    /** Sengaja tidak diimport (mis. baris uji / pesanan batal). */
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Belum diperbaiki',
            self::Fixed => 'Sudah diperbaiki',
            self::Retried => 'Gagal lagi (lihat batch baru)',
            self::Ignored => 'Diabaikan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Fixed => 'success',
            self::Retried => 'warning',
            self::Ignored => 'gray',
        };
    }
}
