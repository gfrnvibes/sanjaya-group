<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * % potongan admin & iklan per channel (riwayat).
 */
#[Fillable(['channel_id', 'effective_from', 'admin_fee_pct', 'ads_pct'])]
class ChannelFeeRate extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'admin_fee_pct' => 'decimal:4',
            'ads_pct' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
