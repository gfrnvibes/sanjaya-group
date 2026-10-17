<?php

namespace App\Models;

use App\Enums\ProductKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MODEL produk, mis. BC1 "Baju Chef 01".
 */
#[Fillable(['code', 'name', 'category_id', 'kind', 'default_margin_pct', 'is_active'])]
class ProductModel extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'default_margin_pct' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'model_id');
    }

    /** @return HasMany<Bom, $this> */
    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class, 'model_id');
    }
}
