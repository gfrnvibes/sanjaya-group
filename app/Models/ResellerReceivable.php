<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * View v_reseller_ar: PIUTANG per reseller.
 */
#[Table(name: 'v_reseller_ar', key: 'reseller_id')]
class ResellerReceivable extends ReadOnlyModel
{
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'billed' => 'decimal:2',
            'returns_credit' => 'decimal:2',
            'paid' => 'decimal:2',
            'outstanding' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class)->withTrashed();
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class)->withTrashed();
    }
}
