<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SalesOrderStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Diproses',
            self::Completed => 'Selesai',
            self::Returned => 'Retur',
            self::Cancelled => 'Batal',
        };
    }
}
