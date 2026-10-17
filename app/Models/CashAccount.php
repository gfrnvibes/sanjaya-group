<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rekening / kas.
 */
#[Fillable(['name', 'bank', 'holder', 'is_active'])]
class CashAccount extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ResellerPayment, $this> */
    public function resellerPayments(): HasMany
    {
        return $this->hasMany(ResellerPayment::class);
    }

    /** @return HasMany<TailorPayment, $this> */
    public function tailorPayments(): HasMany
    {
        return $this->hasMany(TailorPayment::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
