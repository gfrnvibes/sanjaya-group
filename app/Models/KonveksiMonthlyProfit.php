<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * View v_konveksi_monthly_pl: LABA RUGI KONVEKSI per bulan.
 */
#[Table(name: 'v_konveksi_monthly_pl', key: 'month', keyType: 'string')]
class KonveksiMonthlyProfit extends ReadOnlyModel
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'net_sales' => 'decimal:2',
            'hpp' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'expenses' => 'decimal:2',
            'net_profit' => 'decimal:2',
        ];
    }
}
