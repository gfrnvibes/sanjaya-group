<?php

namespace App\Models;

use App\Enums\MaterialKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis bahan: DRILL, LINEN, JARING, KANCING, ...
 */
#[Fillable(['code', 'name', 'kind'])]
class MaterialType extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => MaterialKind::class,
        ];
    }

    /** @return HasMany<Material, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}
