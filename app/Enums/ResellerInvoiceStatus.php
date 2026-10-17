<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ResellerInvoiceStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Terbit',
            self::Void => 'Batal',
        };
    }
}
