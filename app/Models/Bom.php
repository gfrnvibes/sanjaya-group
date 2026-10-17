<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RESEP per model (+ukuran opsional; NULL = semua ukuran), berversi.
 */
#[Fillable(['model_id', 'size_id', 'effective_from', 'note'])]
class Bom extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<ProductModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'model_id');
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /** @return HasMany<BomItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
