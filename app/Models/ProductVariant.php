<?php

namespace App\Models;

use App\Enums\ProductKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SKU VARIAN, mis. BC1HS (model + warna + ukuran).
 */
#[Fillable(['model_id', 'color_id', 'size_id', 'sku', 'name', 'min_stock', 'margin_pct', 'packing_cost', 'is_active'])]
class ProductVariant extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'min_stock' => 'integer',
            'margin_pct' => 'decimal:4',
            'packing_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Produk beli jadi: HPP dari harga beli + packing, bukan dari resep.
     */
    public function isReadyMade(): bool
    {
        $this->loadMissing('model');

        return $this->model?->kind === ProductKind::BeliJadi;
    }

    /** @return BelongsTo<ProductModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'model_id');
    }

    /** @return BelongsTo<Color, $this> */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /** @return HasMany<SkuAlias, $this> */
    public function skuAliases(): HasMany
    {
        return $this->hasMany(SkuAlias::class, 'variant_id');
    }

    /** @return HasMany<VariantCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(VariantCost::class, 'variant_id');
    }

    /** @return HasMany<ResellerPriceHistory, $this> */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(ResellerPriceHistory::class, 'variant_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'variant_id');
    }

    /** @return HasMany<SalesOrderItem, $this> */
    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'variant_id');
    }

    /**
     * HPP & harga berlaku + usulan harga dari view v_variant_pricing.
     *
     * @return HasOne<VariantPricing, $this>
     */
    public function pricing(): HasOne
    {
        return $this->hasOne(VariantPricing::class, 'variant_id');
    }
}
