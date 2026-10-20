<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\SalesImportSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Impor export BigSeller / marketplace.
 */
#[Fillable(['source', 'file_name', 'status', 'row_count', 'error_count', 'imported_by'])]
class SalesImportBatch extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'source' => SalesImportSource::class,
            'status' => ImportBatchStatus::class,
            'row_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /** @return HasMany<SalesOrder, $this> */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'import_batch_id');
    }

    /** @return HasMany<SalesImportFailure, $this> */
    public function failures(): HasMany
    {
        return $this->hasMany(SalesImportFailure::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
