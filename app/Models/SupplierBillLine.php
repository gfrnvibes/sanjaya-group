<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris tagihan supplier: qty pesanan Selesai yang diambil dari lot supplier (FIFO).
 * supplier_id NULL = belum teralokasi (stok minus); qty negatif = pengurang dari retur yang kembali ke stok.
 * is_consignment = titipan grosir bordir (diteruskan utuh ke supplier).
 */
#[Fillable(['sales_order_item_id', 'variant_id', 'supplier_id', 'stock_lot_id', 'sales_return_id', 'billed_on', 'qty', 'unit_cost', 'is_consignment'])]
class SupplierBillLine extends Model
{
    protected function casts(): array
    {
        return [
            'billed_on' => 'date',
            'qty' => 'integer',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_consignment' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
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

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }
}
