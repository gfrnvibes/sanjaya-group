<?php

namespace App\Models;

use App\Enums\SalesImportSource;
use App\Services\SkuMapping;
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
        'website' => 'Website',
        'shopee' => 'Shopee',
        'tiktok' => 'TikTok',
        'lazada' => 'Lazada',
        'lainnya' => 'Lainnya',
    ];

    /** Sumber pemetaan untuk SKU dari file impor pesanan. */
    public static function sourceFor(SalesImportSource $source): string
    {
        return isset(self::SOURCES[$source->value]) ? $source->value : 'lainnya';
    }

    protected static function booted(): void
    {
        // Pemetaan baru/diubah dari menu mana pun: baris "SKU tidak ditemukan" yang kini dikenali otomatis dicoba ulang.
        static::saved(function (): void {
            SkuMapping::retryAfterResponse();
        });
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }
}
