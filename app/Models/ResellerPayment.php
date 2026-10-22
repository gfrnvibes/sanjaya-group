<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PEMBAYARAN reseller -> konveksi. cost_offset = POTONGAN BIAYA (tanpa rekening).
 */
#[Fillable(['reseller_id', 'paid_at', 'amount', 'method', 'cash_account_id', 'proof_path', 'note', 'created_by'])]
class ResellerPayment extends Model
{
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
        ];
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class)->withTrashed();
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /**
     * Biaya yang dibayar reseller atas nama konveksi (method = cost_offset).
     *
     * @return HasMany<Expense, $this>
     */
    public function offsetExpenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
