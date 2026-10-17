<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaterialKind: string implements HasLabel
{
    case Kain = 'kain';
    case Aksesoris = 'aksesoris';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kain => 'Kain',
            self::Aksesoris => 'Aksesoris',
        };
    }
}
