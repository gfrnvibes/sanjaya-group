<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ProductCategory extends Model
{
    /** @return HasMany<ProductModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(ProductModel::class, 'category_id');
    }
}
