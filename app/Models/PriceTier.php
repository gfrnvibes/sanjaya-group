<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TIER 1/2/3: adjustment_pct 0.0500 = harga dasar +5%.
 */
#[Fillable(['code', 'name', 'adjustment_pct'])]
class PriceTier extends Model
{
    protected function casts(): array
    {
        return [
            'adjustment_pct' => 'decimal:4',
        ];
    }

    /** @return HasMany<OwnerTierHistory, $this> */
    public function ownerTierHistories(): HasMany
    {
        return $this->hasMany(OwnerTierHistory::class);
    }
}
