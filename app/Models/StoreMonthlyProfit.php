<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * View v_store_monthly_pl: LABA RUGI TOKO per toko per bulan.
 * id = "{store_id}-{YYYY-MM}". *_estimated = true bila angka memakai estimasi % channel.
 */
#[Table(name: 'v_store_monthly_pl', keyType: 'string')]
class StoreMonthlyProfit extends ReadOnlyModel
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'revenue' => 'decimal:2',
            'cost_of_goods' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'admin_fee_estimated' => 'boolean',
            'ads' => 'decimal:2',
            'ads_estimated' => 'boolean',
            'net_profit' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }
}
