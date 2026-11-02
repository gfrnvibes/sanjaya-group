<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PEMBELIAN BAHAN (header). payment_method: cash (rekening/kas), credit (utang supplier),
 * reseller (dibayar reseller = potongan piutang, reseller_payment_id).
 */
#[Fillable(['supplier_id', 'warehouse_id', 'purchase_date', 'ref_no', 'note', 'payment_method', 'cash_account_id', 'reseller_payment_id', 'created_by'])]
class MaterialPurchase extends Model
{
    public const PAYMENT_METHODS = [
        'cash' => 'Tunai / transfer (kas)',
        'credit' => 'Utang ke supplier',
        'reseller' => 'Dibayar reseller (potong piutang)',
    ];

    protected $attributes = [
        'payment_method' => 'cash',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<MaterialPurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialPurchaseItem::class, 'purchase_id');
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** @return BelongsTo<ResellerPayment, $this> */
    public function resellerPayment(): BelongsTo
    {
        return $this->belongsTo(ResellerPayment::class);
    }

    public function total(): float
    {
        return round((float) $this->items()->sum('total_price'), 2);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
