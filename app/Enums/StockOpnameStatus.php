<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockOpnameStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Diposting',
        };
    }
}
