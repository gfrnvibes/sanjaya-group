<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * View v_variant_pricing: HPP berlaku, margin, harga berlaku, usulan harga (UPDATE HARGA).
 */
#[Table(name: 'v_variant_pricing', key: 'variant_id')]
class VariantPricing extends ReadOnlyModel
{
    protected function casts(): array
    {
        return [
            'hpp' => 'decimal:2',
            'margin_pct' => 'decimal:4',
            'current_price' => 'decimal:2',
            'proposed_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
