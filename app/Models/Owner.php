<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PEMILIK: tenant panel /reseller.
 */
#[Fillable(['code', 'name', 'phone', 'is_active'])]
class Owner extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Reseller, $this> */
    public function resellers(): HasMany
    {
        return $this->hasMany(Reseller::class);
    }

    /** @return HasManyThrough<Store, Reseller, $this> */
    public function stores(): HasManyThrough
    {
        return $this->hasManyThrough(Store::class, Reseller::class);
    }

    /** @return HasMany<OwnerTierHistory, $this> */
    public function tierHistories(): HasMany
    {
        return $this->hasMany(OwnerTierHistory::class);
    }
}
