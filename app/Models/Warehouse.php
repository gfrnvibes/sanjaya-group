<?php

namespace App\Models;

use App\Enums\WarehouseKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GUDANG. kind: bahan (stok bahan), barang_jadi (stok produk), campuran (keduanya).
 */
#[Fillable(['code', 'name', 'kind', 'is_active'])]
class Warehouse extends Model
{
    protected $attributes = [
        'kind' => 'campuran',
    ];

    protected function casts(): array
    {
        return [
            'kind' => WarehouseKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Gudang yang boleh menyimpan barang jadi (barang jadi / campuran).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forGoods(Builder $query): void
    {
        $query->whereIn('kind', [WarehouseKind::BarangJadi, WarehouseKind::Campuran]);
    }

    /**
     * Gudang yang boleh menyimpan bahan (bahan / campuran).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forMaterials(Builder $query): void
    {
        $query->whereIn('kind', [WarehouseKind::Bahan, WarehouseKind::Campuran]);
    }

    /**
     * Pilihan gudang aktif untuk form barang jadi.
     *
     * @return array<int, string>
     */
    public static function goodsOptions(): array
    {
        return static::query()->forGoods()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public static function materialOptions(): array
    {
        return static::query()->forMaterials()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Gudang default barang jadi: pengaturan default_warehouse_id (bila jenisnya cocok), lalu gudang barang jadi pertama.
     */
    public static function defaultForGoods(): ?self
    {
        return static::query()->forGoods()->find(Setting::valueOf('default_warehouse_id'))
            ?? static::query()->where('kind', WarehouseKind::BarangJadi)->where('is_active', true)->orderBy('id')->first()
            ?? static::query()->forGoods()->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Gudang default bahan: pengaturan default_material_warehouse_id (bila jenisnya cocok), lalu gudang bahan pertama.
     */
    public static function defaultForMaterials(): ?self
    {
        return static::query()->forMaterials()->find(Setting::valueOf('default_material_warehouse_id'))
            ?? static::query()->where('kind', WarehouseKind::Bahan)->where('is_active', true)->orderBy('id')->first()
            ?? static::query()->forMaterials()->where('is_active', true)->orderBy('id')->first();
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<MaterialBalance, $this> */
    public function materialBalances(): HasMany
    {
        return $this->hasMany(MaterialBalance::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<MaterialMovement, $this> */
    public function materialMovements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class);
    }
}
