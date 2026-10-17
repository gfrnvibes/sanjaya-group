<?php

namespace App\Models;

use App\Enums\StockAdjustmentReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PENYESUAIAN stok (rusak, sampel, custom, koreksi).
 */
#[Fillable(['warehouse_id', 'adjusted_at', 'reason', 'note', 'created_by'])]
class StockAdjustment extends Model
{
    protected function casts(): array
    {
        return [
            'adjusted_at' => 'datetime',
            'reason' => StockAdjustmentReason::class,
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<StockAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'adjustment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
