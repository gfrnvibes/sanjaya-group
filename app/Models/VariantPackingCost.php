<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Biaya packing varian mulai tanggal tertentu. amount NULL = kembali ke packing default pengaturan.
 */
#[Fillable(['variant_id', 'effective_from', 'amount', 'created_by'])]
class VariantPackingCost extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }
}
