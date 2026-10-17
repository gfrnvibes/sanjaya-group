<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case CostOffset = 'cost_offset';

    public function getLabel(): string
    {
        return match ($this) {
            self::Transfer => 'Transfer',
            self::Cash => 'Tunai',
            self::CostOffset => 'Potongan Biaya',
        };
    }
}
