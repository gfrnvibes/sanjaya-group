<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bulan buku yang sudah ditutup (month = tanggal 1).
 */
#[WithoutTimestamps]
#[Fillable(['month', 'closed_by', 'closed_at'])]
class LedgerClosing extends Model
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
