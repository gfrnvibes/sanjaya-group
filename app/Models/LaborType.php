<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis ongkos kerja: POTONG, JAHIT, BORDIR, OBRAS.
 */
#[Fillable(['code', 'name'])]
class LaborType extends Model
{
    /** @return HasMany<BomItem, $this> */
    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
