<?php

namespace App\Models;

use App\Enums\ChannelKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MASTER CHANNEL: SHOPEE, TIKTOK, WA, OFFLINE, ...
 */
#[Fillable(['code', 'name', 'kind', 'is_active'])]
class Channel extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => ChannelKind::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ChannelFeeRate, $this> */
    public function feeRates(): HasMany
    {
        return $this->hasMany(ChannelFeeRate::class);
    }

    /** @return HasMany<Store, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
