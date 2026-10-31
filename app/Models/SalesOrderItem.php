<?php

namespace App\Models;

use App\Services\InvoiceSync;
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
    'sales_order_id', 'variant_id', 'sku_raw', 'qty', 'dropship_qty', 'revenue',
    'reseller_price_id', 'tier_adjustment_pct', 'reseller_unit_price', 'hpp_unit',
    'reseller_invoice_id', 'is_consignment',
])]
class SalesOrderItem extends Model
{
    protected static function booted(): void
    {
        // Penjualan baru / dilepas dari tagihan: tagihan reseller otomatis disinkronkan setelah respons.
        static::saved(function (): void {
            InvoiceSync::afterResponse();
        });
    }

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'dropship_qty' => 'integer', // beli jadi: qty dikirim langsung supplier (tanpa stok)
            'revenue' => 'decimal:2',
            'tier_adjustment_pct' => 'decimal:4',
            'reseller_unit_price' => 'decimal:2',
            'hpp_unit' => 'decimal:2',
            'bill_amount' => 'decimal:2',
            'margin' => 'decimal:2',
            'is_consignment' => 'boolean',
        ];
    }

    /** Qty yang diambil dari stok konveksi (sisanya dikirim langsung supplier). */
    public function stockQty(): int
    {
        return $this->qty - (int) $this->dropship_qty;
    }

    /** @return HasMany<SupplierBillLine, $this> */
    public function supplierBillLines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
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
