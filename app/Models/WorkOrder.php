<?php

namespace App\Models;

use App\Enums\WageType;
use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SPK / list potongan.
 */
#[Fillable(['code', 'tailor_id', 'cutter_id', 'warehouse_id', 'goods_warehouse_id', 'cut_date', 'finished_date', 'status', 'note', 'created_by'])]
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

    /** @return BelongsTo<Tailor, $this> */
    public function cutter(): BelongsTo
    {
        return $this->belongsTo(Tailor::class, 'cutter_id');
    }

    /**
     * Upah SPK per jenis (ongkos dari resep model, dicatat saat dipotong):
     *  - JAHIT: pcs x ongkos JAHIT; pcs = hasil bagus bila SPK selesai, selain itu qty potong;
     *  - POTONG: qty potong x ongkos POTONG (dibayar ke tukang potong).
     * Ongkos lain (obras, bordir) tidak termasuk: dibayar lewat Biaya Operasional.
     */
    public function laborTotal(WageType $type = WageType::Jahit): float
    {
        $done = $this->status === WorkOrderStatus::Done;

        return (float) $this->items()->get()->sum(fn (WorkOrderItem $item): float => $type === WageType::Potong
            ? $item->qty_cut * (float) $item->cutting_cost_snapshot
            : ($done ? $item->qty_good : $item->qty_cut) * (float) $item->sewing_cost_snapshot);
    }

    /**
     * Sisa upah per jenis yang belum dibayar (tidak pernah negatif).
     */
    public function laborDue(?int $exceptPaymentId = null, WageType $type = WageType::Jahit): float
    {
        $paid = (float) $this->tailorPayments()
            ->where('wage_type', $type)
            ->when($exceptPaymentId, fn ($query, $id) => $query->whereKeyNot($id))
            ->sum('amount');

        return max(0.0, round($this->laborTotal($type) - $paid, 2));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
