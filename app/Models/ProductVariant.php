<?php

namespace App\Models;

use App\Enums\ProductKind;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
     * Packing diubah dari form Varian / import Excel setelah ada riwayat packing bertanggal: dicatat juga sebagai
     * riwayat mulai hari ini, supaya tidak tertutup riwayat lama (riwayat bertanggal didahulukan).
     */
    protected static function booted(): void
    {
        // SKU kosong = otomatis dari rumus (kode model + kode warna + ukuran).
        static::saving(function (ProductVariant $variant): void {
            if (blank($variant->sku)) {
                $variant->sku = $variant->expectedSku();

                $taken = self::withTrashed()->whereRaw('lower(sku) = ?', [Str::lower($variant->sku)])->whereKeyNot($variant->getKey())->exists();

                if ($taken || $variant->sku === '') {
                    throw new BusinessRuleException("SKU otomatis \"{$variant->sku}\" sudah dipakai varian lain atau kosong; isi SKU secara manual.");
                }
            }
        });

        static::updated(function (ProductVariant $variant): void {
            if ($variant->wasChanged('packing_cost') && $variant->packingCosts()->exists()) {
                VariantPackingCost::updateOrCreate(
                    ['variant_id' => $variant->id, 'effective_from' => today()->toDateString()],
                    ['amount' => $variant->packing_cost, 'created_by' => auth()->id()],
                );
            }
        });
    }

    /**
     * Rumus SKU: kode model + kode warna + ukuran, tanpa pemisah (mis. BC1 + H + S = BC1HS).
     */
    public static function skuFor(?string $modelCode, ?string $colorCode, ?string $sizeCode): string
    {
        return $modelCode.$colorCode.$sizeCode;
    }

    public function expectedSku(): string
    {
        return self::skuFor(
            ProductModel::withTrashed()->whereKey($this->model_id)->value('code'),
            $this->color_id ? Color::query()->whereKey($this->color_id)->value('code') : null,
            $this->size_id ? Size::query()->whereKey($this->size_id)->value('code') : null,
        );
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

    /** @return HasMany<VariantPackingCost, $this> */
    public function packingCosts(): HasMany
    {
        return $this->hasMany(VariantPackingCost::class, 'variant_id');
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
