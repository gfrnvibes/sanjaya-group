<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Item SPK + snapshot biaya per pcs saat dipotong.
 */
#[WithoutTimestamps]
#[Fillable(['work_order_id', 'variant_id', 'qty_cut', 'qty_good', 'qty_reject', 'material_cost_snapshot', 'labor_cost_snapshot'])]
class WorkOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty_cut' => 'integer',
            'qty_good' => 'integer',
            'qty_reject' => 'integer',
            'material_cost_snapshot' => 'decimal:2',
            'labor_cost_snapshot' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    /** @return MorphMany<MaterialMovement, $this> */
    public function materialMovements(): MorphMany
    {
        return $this->morphMany(MaterialMovement::class, 'source');
    }
}
