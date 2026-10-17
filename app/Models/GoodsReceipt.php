<?php

namespace App\Models;

use App\Enums\GoodsReceiptSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BARANG MASUK manual (borongan, beli jadi, retur, ...).
 */
#[Fillable(['receipt_no', 'warehouse_id', 'received_at', 'source', 'supplier_id', 'note', 'created_by'])]
class GoodsReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'source' => GoodsReceiptSource::class,
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'receipt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
