<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * 1 pesanan / resi per toko. Reseller & pemilik diturunkan dari store (tidak disalin).
 */
#[Fillable(['store_id', 'warehouse_id', 'order_no', 'order_date', 'status', 'buyer_name', 'platform_fee', 'shipping_fee', 'import_batch_id', 'note', 'created_by', 'import_key'])]
class SalesOrder extends Model
{
    protected $attributes = [
        'status' => 'completed',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'status' => SalesOrderStatus::class,
            'platform_fee' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }

    /** @return HasOneThrough<Reseller, Store, $this> */
    public function reseller(): HasOneThrough
    {
        return $this->hasOneThrough(Reseller::class, Store::class, 'id', 'id', 'store_id', 'reseller_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<SalesOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /** @return BelongsTo<SalesImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(SalesImportBatch::class, 'import_batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
