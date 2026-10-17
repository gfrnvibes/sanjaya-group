<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BomLineType: string implements HasLabel
{
    case Material = 'material';
    case Labor = 'labor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Material => 'Bahan',
            self::Labor => 'Ongkos Kerja',
        };
    }
}
