<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Upah jahit yang dibayar (arus kas, bukan HPP).
 */
#[Fillable(['tailor_id', 'work_order_id', 'paid_at', 'amount', 'cash_account_id', 'note', 'created_by'])]
class TailorPayment extends Model
{
    /**
     * SPK boleh dibuat tanpa penjahit (baru diketahui saat bayaran mingguan):
     * pembayaran pertama untuk SPK tersebut sekaligus mencatat penjahitnya di SPK.
     */
    protected static function booted(): void
    {
        static::saved(function (TailorPayment $payment): void {
            if ($payment->work_order_id !== null && $payment->tailor_id !== null) {
                WorkOrder::query()->whereKey($payment->work_order_id)->whereNull('tailor_id')->update(['tailor_id' => $payment->tailor_id]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'paid_at' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Tailor, $this> */
    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
