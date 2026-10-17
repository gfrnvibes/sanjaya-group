<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * LEDGER barang jadi (append-only). UPDATE/DELETE ditolak trigger; koreksi = baris baru.
 * qty: + masuk, - keluar. Saldo di stock_balances diperbarui trigger.
 */
#[Fillable(['variant_id', 'warehouse_id', 'moved_at', 'qty', 'movement_type', 'unit_cost', 'source_type', 'source_id', 'note', 'created_by'])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
            'qty' => 'integer',
            'movement_type' => StockMovementType::class,
            'unit_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
