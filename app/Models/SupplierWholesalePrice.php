<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga grosir bordir dari supplier per model: berlaku otomatis bila total qty SKU yang sama (warna + ukuran sama) dalam 1 pesanan >= min_qty.
 * Pesanan grosir = titipan: harga ke reseller = harga ini, diteruskan utuh ke supplier.
 */
#[Fillable(['supplier_id', 'model_id', 'min_qty', 'unit_price', 'effective_from', 'note'])]
class SupplierWholesalePrice extends Model
{
    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'unit_price' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<ProductModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'model_id');
    }
}
