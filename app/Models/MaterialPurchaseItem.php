<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Item pembelian bahan. unit_cost = total_price / qty_base (GENERATED).
 */
#[WithoutTimestamps]
#[Fillable(['purchase_id', 'material_id', 'qty_pack', 'qty_base', 'total_price'])]
class MaterialPurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty_pack' => 'decimal:3',
            'qty_base' => 'decimal:3',
            'total_price' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<MaterialPurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(MaterialPurchase::class, 'purchase_id');
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return MorphMany<MaterialMovement, $this> */
    public function materialMovements(): MorphMany
    {
        return $this->morphMany(MaterialMovement::class, 'source');
    }
}
