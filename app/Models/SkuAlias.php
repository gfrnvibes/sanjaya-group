<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PEMETAAN SKU BigSeller/marketplace -> varian.
 */
#[Fillable(['alias', 'variant_id', 'source'])]
class SkuAlias extends Model
{
    /** Sumber pemetaan: kode => label. */
    public const SOURCES = [
        'bigseller' => 'BigSeller',
        'shopee' => 'Shopee',
        'tiktok' => 'TikTok',
        'lainnya' => 'Lainnya',
    ];

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
