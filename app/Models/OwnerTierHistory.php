<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tier per PEMILIK + BERLAKU MULAI.
 */
#[Fillable(['owner_id', 'price_tier_id', 'effective_from', 'note'])]
class OwnerTierHistory extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return BelongsTo<PriceTier, $this> */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}
