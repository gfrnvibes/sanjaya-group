<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IKLAN aktual (ditanggung toko).
 */
#[Fillable(['store_id', 'spend_date', 'amount', 'note', 'created_by'])]
class StoreAdSpend extends Model
{
    protected function casts(): array
    {
        return [
            'spend_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
