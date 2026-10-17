<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * View v_sales_lines: baris penjualan + store/reseller/owner/channel (join sekali untuk laporan).
 */
#[Table(name: 'v_sales_lines')]
class SalesLine extends ReadOnlyModel
{
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'status' => SalesOrderStatus::class,
            'qty' => 'integer',
            'revenue' => 'decimal:2',
            'reseller_unit_price' => 'decimal:2',
            'bill_amount' => 'decimal:2',
            'margin' => 'decimal:2',
            'hpp_unit' => 'decimal:2',
            'hpp_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
