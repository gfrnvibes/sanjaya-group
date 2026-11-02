<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MODAL & PRIVE: setoran modal pemilik (kas masuk) atau prive / penarikan pemilik (kas keluar).
 */
#[Fillable(['transacted_at', 'type', 'amount', 'cash_account_id', 'note', 'created_by'])]
class EquityTransaction extends Model
{
    public const DEPOSIT = 'setoran';

    public const DRAWING = 'prive';

    public const TYPES = [
        self::DEPOSIT => 'Setoran modal',
        self::DRAWING => 'Prive (penarikan pemilik)',
    ];

    protected function casts(): array
    {
        return [
            'transacted_at' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** Setoran positif, prive negatif. */
    public function signedAmount(): float
    {
        return $this->type === self::DRAWING ? -(float) $this->amount : (float) $this->amount;
    }
}
