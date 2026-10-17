<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BaseUom: string implements HasLabel
{
    case Cm = 'cm';
    case M = 'm';
    case Pcs = 'pcs';
    case Gr = 'gr';

    public function getLabel(): string
    {
        return $this->value;
    }
}
