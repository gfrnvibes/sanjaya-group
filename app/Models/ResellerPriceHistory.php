<?php

namespace App\Models;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HARGA RESELLER (harga dasar) + BERLAKU MULAI.
 */
#[Fillable(['variant_id', 'effective_from', 'base_price', 'variant_cost_id', 'margin_pct', 'source', 'approved_by', 'note'])]
class ResellerPriceHistory extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'base_price' => 'decimal:2',
            'margin_pct' => 'decimal:4',
            'source' => PriceSource::class,
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /** @return BelongsTo<VariantCost, $this> */
    public function variantCost(): BelongsTo
    {
        return $this->belongsTo(VariantCost::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<SalesOrderItem, $this> */
    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'reseller_price_id');
    }
}
