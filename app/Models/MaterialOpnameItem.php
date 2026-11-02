<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * diff = counted_qty - system_qty (GENERATED), satuan dasar bahan (cm/pcs).
 */
#[WithoutTimestamps]
#[Fillable(['opname_id', 'material_id', 'system_qty', 'counted_qty'])]
class MaterialOpnameItem extends Model
{
    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'diff' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<MaterialOpname, $this> */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(MaterialOpname::class, 'opname_id');
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
