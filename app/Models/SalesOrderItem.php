<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Baris penjualan + SNAPSHOT harga konveksi saat transaksi.
 * bill_amount = qty x reseller_unit_price; margin = revenue - bill_amount (GENERATED).
 */
#[WithoutTimestamps]
#[Fillable([
    'sales_order_id', 'variant_id', 'sku_raw', 'qty', 'revenue',
    'reseller_price_id', 'tier_adjustment_pct', 'reseller_unit_price', 'hpp_unit',
    'reseller_invoice_id',
])]
class SalesOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'revenue' => 'decimal:2',
            'tier_adjustment_pct' => 'decimal:4',
            'reseller_unit_price' => 'decimal:2',
            'hpp_unit' => 'decimal:2',
            'bill_amount' => 'decimal:2',
            'margin' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<ResellerPriceHistory, $this> */
    public function resellerPrice(): BelongsTo
    {
        return $this->belongsTo(ResellerPriceHistory::class, 'reseller_price_id');
    }

    /** @return BelongsTo<ResellerInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ResellerInvoice::class, 'reseller_invoice_id');
    }

    /** @return HasMany<SalesReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
