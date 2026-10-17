<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RESELLER / brand milik seorang PEMILIK.
 */
#[Fillable(['owner_id', 'name', 'opening_balance', 'opening_balance_date', 'is_active'])]
class Reseller extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return HasMany<Store, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /** @return HasManyThrough<SalesOrder, Store, $this> */
    public function salesOrders(): HasManyThrough
    {
        return $this->hasManyThrough(SalesOrder::class, Store::class);
    }

    /** @return HasMany<ResellerInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(ResellerInvoice::class);
    }

    /** @return HasMany<ResellerPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ResellerPayment::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Ringkasan piutang dari view v_reseller_ar.
     *
     * @return HasOne<ResellerReceivable, $this>
     */
    public function receivable(): HasOne
    {
        return $this->hasOne(ResellerReceivable::class);
    }
}
