<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Jenis upah produksi per SPK: jahit (ongkos JAHIT x pcs jadi) atau potong (ongkos POTONG x pcs dipotong).
 */
enum WageType: string implements HasLabel
{
    case Jahit = 'jahit';
    case Potong = 'potong';

    public function getLabel(): string
    {
        return match ($this) {
            self::Jahit => 'Upah Jahit',
            self::Potong => 'Upah Potong',
        };
    }

    /** Kode jenis ongkos di resep (BOM) yang menjadi dasar upah ini. */
    public function laborCode(): string
    {
        return match ($this) {
            self::Jahit => 'JAHIT',
            self::Potong => 'POTONG',
        };
    }
}
