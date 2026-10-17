<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImportBatchStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Done => 'Selesai',
            self::Failed => 'Gagal',
        };
    }
}
