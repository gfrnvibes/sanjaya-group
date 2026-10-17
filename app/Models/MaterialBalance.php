<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache saldo bahan per bahan per gudang. Diisi trigger dari material_movements;
 * jangan ditulis dari aplikasi.
 */
class MaterialBalance extends Model
{
    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
