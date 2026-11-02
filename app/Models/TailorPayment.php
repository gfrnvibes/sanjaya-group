<?php

namespace App\Models;

use App\Enums\WageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Upah produksi yang dibayar per SPK (arus kas, bukan HPP): upah jahit ke penjahit, upah potong ke tukang potong.
 * Tanpa penjahit = upah gabungan (mis. mingguan semua penjahit). reseller_payment_id = dibayar reseller (potong piutang).
 */
#[Fillable(['tailor_id', 'work_order_id', 'wage_type', 'paid_at', 'amount', 'cash_account_id', 'reseller_payment_id', 'note', 'created_by'])]
class TailorPayment extends Model
{
    protected $attributes = [
        'wage_type' => 'jahit',
    ];

    /**
     * SPK boleh dibuat tanpa penjahit / tukang potong (baru diketahui saat bayaran mingguan):
     * pembayaran pertama untuk SPK tersebut sekaligus mencatat orangnya di SPK.
     */
    protected static function booted(): void
    {
        static::saved(function (TailorPayment $payment): void {
            if ($payment->work_order_id === null || $payment->tailor_id === null) {
                return;
            }

            $column = $payment->wage_type === WageType::Potong ? 'cutter_id' : 'tailor_id';
            WorkOrder::query()->whereKey($payment->work_order_id)->whereNull($column)->update([$column => $payment->tailor_id]);
        });
    }

    protected function casts(): array
    {
        return [
            'paid_at' => 'date',
            'amount' => 'decimal:2',
            'wage_type' => WageType::class,
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

    /** @return BelongsTo<ResellerPayment, $this> */
    public function resellerPayment(): BelongsTo
    {
        return $this->belongsTo(ResellerPayment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
