<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Kepadatan baris tabel (preferensi per user).
 */
enum TableDensity: string implements HasDescription, HasLabel
{
    case Comfortable = 'comfortable';
    case Compact = 'compact';

    public function getLabel(): string
    {
        return match ($this) {
            self::Comfortable => 'Nyaman',
            self::Compact => 'Padat',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Comfortable => 'Jarak baris standar Filament.',
            self::Compact => 'Baris lebih rapat & huruf sedikit lebih kecil: lebih banyak data per layar.',
        };
    }
}
