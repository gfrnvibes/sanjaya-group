<?php

namespace App\Models;

use App\Observers\TierObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tier per PEMILIK + BERLAKU MULAI.
 */
#[Fillable(['owner_id', 'price_tier_id', 'effective_from', 'note'])]
#[ObservedBy(TierObserver::class)]
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
        return $this->belongsTo(Owner::class)->withTrashed();
    }

    /** @return BelongsTo<PriceTier, $this> */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}
