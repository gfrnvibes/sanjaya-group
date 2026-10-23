<?php

namespace App\Models;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Imports\ImportRegistry;
use App\Services\SkuMatcher;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris import yang perlu dicek (semua jenis data; nama tabel historis dari import pesanan).
 * importer = kategori data (kunci ImportRegistry); values = isi kolom asli per LABEL HEADER (TANGGAL, NAMA TOKO, ...).
 */
#[Fillable(['importer', 'batch_id', 'upload_id', 'file_name', 'line', 'group_key', 'values', 'reason', 'message', 'status', 'retry_of_id', 'resolved_batch_id', 'resolved_at', 'created_by'])]
class SalesImportFailure extends Model
{
    protected $attributes = [
        'importer' => ImportRegistry::SALES_ORDERS,
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<SalesImportFailure>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ImportFailureStatus::Open);
    }

    public function value(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Nama barang untuk pencocokan manual: NAMA PRODUK dari file (BigSeller), atau nama/varian pada data.
     */
    public function itemName(): ?string
    {
        foreach (['NAMA PRODUK', 'NAMA VARIAN', 'NAMA MODEL', 'NAMA BAHAN', 'NAMA'] as $label) {
            if (($value = $this->value($label)) !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * SKU mentah di baris (SKU BIG SELLER / SKU VARIAN / ALIAS).
     */
    public function rawSku(): ?string
    {
        return $this->value('SKU BIG SELLER') ?? $this->value('SKU VARIAN') ?? $this->value('ALIAS');
    }

    /**
     * Saran varian dari SKU mentah, nama produk, dan nama variasi baris ini.
     *
     * @return list<array{variant: ProductVariant, score: int, reasons: list<string>}>
     */
    public function skuSuggestions(int $limit = 5): array
    {
        return app(SkuMatcher::class)->suggest($this->rawSku(), $this->itemName(), $limit, $this->value('NAMA VARIASI'));
    }

    public function categoryLabel(): string
    {
        return ImportRegistry::label($this->importer);
    }
}
