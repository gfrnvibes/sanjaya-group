<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SPK / list potongan.
 */
#[Fillable(['code', 'tailor_id', 'warehouse_id', 'goods_warehouse_id', 'cut_date', 'finished_date', 'status', 'note', 'created_by'])]
class WorkOrder extends Model
{
    protected $attributes = [
        'status' => 'cutting',
    ];

    protected function casts(): array
    {
        return [
            'cut_date' => 'date',
            'finished_date' => 'date',
            'status' => WorkOrderStatus::class,
        ];
    }

    /** @return BelongsTo<Tailor, $this> */
    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    /**
     * Gudang bahan: bahan keluar saat dipotong.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Gudang barang jadi: hasil jahit masuk saat SPK selesai (SPK lama: kosong = gudang bahan).
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function goodsWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'goods_warehouse_id');
    }

    public function goodsWarehouseId(): int
    {
        return (int) ($this->goods_warehouse_id ?? $this->warehouse_id);
    }

    /** @return HasMany<WorkOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    /** @return HasMany<TailorPayment, $this> */
    public function tailorPayments(): HasMany
    {
        return $this->hasMany(TailorPayment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
