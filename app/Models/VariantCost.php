<?php

namespace App\Models;

use App\Enums\VariantCostSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HPP per varian (riwayat). total_cost = material_cost + labor_cost (GENERATED).
 */
#[Fillable(['variant_id', 'effective_from', 'material_cost', 'labor_cost', 'source'])]
class VariantCost extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'material_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'source' => VariantCostSource::class,
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /** @return HasMany<ResellerPriceHistory, $this> */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(ResellerPriceHistory::class);
    }
}
