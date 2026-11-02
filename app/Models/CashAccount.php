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
    /** Setiap rekening punya akun kas sendiri di bagan akun. */
    protected static function booted(): void
    {
        static::created(fn (CashAccount $cash) => Account::forCashAccount($cash));
        static::updated(function (CashAccount $cash): void {
            if ($cash->wasChanged('name')) {
                Account::query()->where('cash_account_id', $cash->id)->update(['name' => 'Kas - '.$cash->name]);
            }
        });
    }

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

    /** @return HasMany<SupplierPayment, $this> */
    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
