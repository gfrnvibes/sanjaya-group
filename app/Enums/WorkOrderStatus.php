<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasLabel
{
    case Cutting = 'cutting';
    case Sewing = 'sewing';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cutting => 'Dipotong',
            self::Sewing => 'Dijahit',
            self::Done => 'Selesai',
            self::Cancelled => 'Batal',
        };
    }
}
