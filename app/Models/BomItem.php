<?php

namespace App\Models;

use App\Enums\BomColorMode;
use App\Enums\BomLineType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris resep: bahan (follow_variant / fixed) atau ongkos kerja.
 */
#[WithoutTimestamps]
#[Fillable(['bom_id', 'line_type', 'material_type_id', 'color_mode', 'material_id', 'qty_per_unit', 'labor_type_id', 'labor_rate'])]
class BomItem extends Model
{
    protected function casts(): array
    {
        return [
            'line_type' => BomLineType::class,
            'color_mode' => BomColorMode::class,
            'qty_per_unit' => 'decimal:4',
            'labor_rate' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Bom, $this> */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /** @return BelongsTo<MaterialType, $this> */
    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<LaborType, $this> */
    public function laborType(): BelongsTo
    {
        return $this->belongsTo(LaborType::class);
    }
}
