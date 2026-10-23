<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot stok barang jadi dari 1 baris Barang Masuk sumber Beli Jadi: dasar FIFO tagihan supplier.
 */
#[Fillable(['variant_id', 'supplier_id', 'goods_receipt_item_id', 'received_at', 'unit_cost', 'qty', 'qty_remaining'])]
class StockLot extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'unit_cost' => 'decimal:2',
            'qty' => 'integer',
            'qty_remaining' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<GoodsReceiptItem, $this> */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
    }

    /** @return HasMany<SupplierBillLine, $this> */
    public function billLines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }
}
