<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Enums\Width;

/**
 * Lebar area konten panel (preferensi per user).
 */
enum LayoutWidth: string implements HasDescription, HasLabel
{
    case Standard = 'standard';
    case Wide = 'wide';
    case Full = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Standar',
            self::Wide => 'Lebar',
            self::Full => 'Penuh',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Standard => 'Maks. 1280 px, nyaman dibaca di laptop.',
            self::Wide => 'Maks. 1536 px, tabel lebih banyak kolom terlihat.',
            self::Full => 'Selebar layar, cocok untuk monitor besar.',
        };
    }

    public function width(): Width
    {
        return match ($this) {
            self::Standard => Width::SevenExtraLarge,
            self::Wide => Width::ScreenTwoExtraLarge,
            self::Full => Width::Full,
        };
    }
}
