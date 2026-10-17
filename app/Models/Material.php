<?php

namespace App\Models;

use App\Enums\BaseUom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STOK BAHAN, mis. "DRILL - HITAM".
 */
#[Fillable(['material_type_id', 'color_id', 'code', 'base_uom', 'pack_uom', 'qty_per_pack', 'min_stock', 'is_active'])]
class Material extends Model
{
    protected function casts(): array
    {
        return [
            'base_uom' => BaseUom::class,
            'qty_per_pack' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<MaterialType, $this> */
    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    /** @return BelongsTo<Color, $this> */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /** @return HasMany<MaterialPurchaseItem, $this> */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(MaterialPurchaseItem::class);
    }

    /** @return HasMany<MaterialMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class);
    }

    /** @return HasMany<MaterialBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(MaterialBalance::class);
    }
}
