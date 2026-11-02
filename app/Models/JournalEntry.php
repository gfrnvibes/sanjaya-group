<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Services\GeneralLedger;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * JURNAL. is_auto = disusun dari transaksi (tidak bisa diubah, disusun ulang otomatis);
 * manual = jurnal umum (saldo awal, penyusutan, pinjaman, koreksi). store_id NULL = buku konveksi.
 */
#[Fillable(['store_id', 'entry_date', 'number', 'description', 'source_type', 'source_key', 'is_auto', 'created_by'])]
class JournalEntry extends Model
{
    /** Label sumber jurnal otomatis. */
    public const SOURCES = [
        'manual' => 'Jurnal manual',
        'opening' => 'Saldo awal',
        'sales' => 'Penjualan',
        'return' => 'Retur penjualan',
        'payment' => 'Pembayaran reseller',
        'expense' => 'Biaya operasional',
        'equity' => 'Modal & prive',
        'material_purchase' => 'Pembelian bahan',
        'material_stock' => 'Penyesuaian / opname bahan',
        'work_order' => 'SPK produksi',
        'wage' => 'Pembayaran upah',
        'goods_receipt' => 'Barang masuk',
        'supplier_bill' => 'Tagihan supplier',
        'supplier_payment' => 'Pembayaran supplier',
        'stock' => 'Penyesuaian / opname barang jadi',
        'store_day' => 'Penjualan & biaya toko',
        'store_ads' => 'Estimasi iklan toko',
        'tax' => 'Estimasi pajak',
    ];

    protected $attributes = [
        'is_auto' => false,
        'source_type' => 'manual',
    ];

    protected static function booted(): void
    {
        static::saving(function (JournalEntry $entry): void {
            if ($entry->is_auto) {
                return;
            }

            app(GeneralLedger::class)->ensureOpen($entry->entry_date, 'Jurnal');

            if ($entry->isDirty('entry_date') && $entry->getOriginal('entry_date') !== null) {
                app(GeneralLedger::class)->ensureOpen($entry->getOriginal('entry_date'), 'Jurnal');
            }
        });

        static::creating(function (JournalEntry $entry): void {
            if (! $entry->is_auto && blank($entry->number)) {
                $entry->number = DocumentNumber::next('JU-'.$entry->entry_date->format('ym').'-', 'journal_entries', 'number');
            }
        });

        // Tanggal & buku baris jurnal mengikuti header.
        static::saved(function (JournalEntry $entry): void {
            if (! $entry->is_auto && $entry->wasChanged(['entry_date', 'store_id'])) {
                $entry->lines()->update(['entry_date' => $entry->entry_date, 'store_id' => $entry->store_id]);
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if ($entry->is_auto) {
                throw new BusinessRuleException('Jurnal otomatis tidak bisa dihapus; ubah transaksi sumbernya.');
            }

            app(GeneralLedger::class)->ensureOpen($entry->entry_date, 'Jurnal');
        });
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'is_auto' => 'boolean',
        ];
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source_type] ?? $this->source_type;
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
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
