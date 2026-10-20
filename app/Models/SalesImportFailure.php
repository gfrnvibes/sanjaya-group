<?php

namespace App\Models;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris import pesanan yang gagal. values = isi kolom input asli (TANGGAL, NAMA TOKO, ...).
 */
#[Fillable(['batch_id', 'line', 'values', 'reason', 'message', 'status', 'retry_of_id', 'resolved_batch_id', 'resolved_at'])]
class SalesImportFailure extends Model
{
    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'reason' => ImportFailureReason::class,
            'status' => ImportFailureStatus::class,
            'line' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SalesImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SalesImportBatch::class, 'batch_id');
    }

    /** @return BelongsTo<SalesImportBatch, $this> */
    public function resolvedBatch(): BelongsTo
    {
        return $this->belongsTo(SalesImportBatch::class, 'resolved_batch_id');
    }

    public function value(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
