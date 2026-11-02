<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STOK OPNAME per gudang. Saat posted, selisih -> stock_movements(opname).
 */
#[Fillable(['warehouse_id', 'counted_at', 'status', 'posted_at', 'is_opening', 'note', 'created_by'])]
class StockOpname extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'is_opening' => false,
    ];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'posted_at' => 'datetime',
            'is_opening' => 'boolean',
            'status' => StockOpnameStatus::class,
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<StockOpnameItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
