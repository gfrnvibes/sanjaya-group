<?php

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\MissingPriceException;
use App\Models\ProductVariant;
use App\Models\SalesImportBatch;
use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Models\SkuAlias;
use App\Models\Store;
use App\Support\Spreadsheet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor sheet "INPUT ORDERAN RESELLER": 1 baris = 1 pesanan.
 * Header dicari otomatis (boleh ada judul di atasnya & kolom otomatis di sampingnya).
 * Tiap baris disimpan terpisah: baris valid tersimpan, baris error dilaporkan.
 * Nomor/ID transaksi mengenali pesanan lintas file; sidik isi file dan nomor baris mengenali unggahan ulang.
 * Bisa beberapa file sekaligus (importMany): 1 file = 1 batch impor.
 * Baris gagal disimpan (nilai asli + kategori alasan) agar bisa diunduh, diperbaiki, lalu diimport ulang;
 * kolom ID GAGAL pada file baris gagal menandai baris lama sebagai sudah diperbaiki / gagal lagi.
 */
class SalesOrderImporter
{
    /** @var Collection<string, Store>|null */
    private ?Collection $stores = null;

    /** @var Collection<string, ProductVariant>|null */
    private ?Collection $variants = null;

    /** @var Collection<string, int>|null */
    private ?Collection $aliases = null;

    /** Kolom input file (label => kunci); juga urutan kolom file baris gagal. */
    public const COLUMNS = [
        'TANGGAL' => 'order_date',
        'NAMA TOKO' => 'store',
        'SKU VARIAN' => 'sku',
        'SKU BIG SELLER' => 'sku_bigseller',
        'QTY' => 'qty',
        'PENDAPATAN TOKO' => 'revenue',
        'NO PESANAN' => 'order_no',
        'ID TRANSAKSI IMPOR' => 'import_key',
    ];

    /** Header export BigSeller (Analisa Bisnis > SKU Toko) => kunci. Kolom TANGGAL ditambahkan manual di belakang; sel kosong memakai tanggal dari form impor. */
    public const BIGSELLER_COLUMNS = [
        'TANGGAL' => 'order_date',
        'NAMA TOKO' => 'store',
        'NOMOR SKU TOKO' => 'sku_bigseller',
        'PENJUALAN PRODUK VALID' => 'qty',
        'DANA PENJUALAN PRODUK VALID' => 'revenue',
        'NO PESANAN' => 'order_no',
        'NO. PESANAN' => 'order_no',
        'ID TRANSAKSI IMPOR' => 'import_key',
    ];

    /** Kolom ID baris gagal (terisi di file baris gagal, kosong di file biasa). */
    public const FAILURE_ID_COLUMN = 'ID GAGAL';

    /** @var list<array{line: int, values: array<string, string>, reason: ImportFailureReason, message: string, retry_of: ?int}> */
    private array $failures = [];

    /** @var list<int> ID baris gagal lama yang berhasil diimport ulang */
    private array $fixed = [];

    public function __construct(private SalesOrderService $orders) {}

    /**
     * Impor beberapa file sekaligus. Tiap file = 1 batch impor; pesanan dengan nomor/ID yang sama
     * dikenali kembali. Dua baris identik dalam satu file tetap menjadi dua pesanan terpisah.
     *
     * @param  list<array{path: string, name: string}>  $files
     * @param  array{warehouse_id: int, status: string, source: string, order_date?: ?string}  $options
     * @return array{batches: list<SalesImportBatch>, files: int, rows: int, created: int, skipped: int, fixed: int, errors: list<string>}
     */
    public function importMany(array $files, array $options): array
    {
        $total = ['batches' => [], 'files' => count($files), 'rows' => 0, 'created' => 0, 'skipped' => 0, 'fixed' => 0, 'errors' => []];

        foreach ($files as $file) {
            $result = $this->import($file['path'], $file['name'], $options);

            $total['batches'][] = $result['batch'];
            $total['rows'] += $result['rows'];
            $total['created'] += $result['created'];
            $total['skipped'] += $result['skipped'];
            $total['fixed'] += $result['fixed'];

            foreach ($result['errors'] as $error) {
                $total['errors'][] = count($files) > 1 ? "{$file['name']} — {$error}" : $error;
            }
        }

        return $total;
    }

    /**
     * @param  array{warehouse_id: int, status: string, source: string, order_date?: ?string}  $options
     * @return array{batch: SalesImportBatch, rows: int, created: int, skipped: int, fixed: int, errors: list<string>}
     */
    public function import(string $path, string $fileName, array $options): array
    {
        $batch = SalesImportBatch::create([
            'source' => $options['source'] ?? SalesImportSource::Manual->value,
            'file_name' => $fileName,
            'status' => ImportBatchStatus::Pending,
            'imported_by' => auth()->id(),
        ]);

        $this->stores = $this->variants = $this->aliases = null;
        $this->failures = [];
        $this->fixed = [];

        try {
            [$rows, $errors, $lineCount] = $this->parse($path, $fileName, $options['order_date'] ?? null);
            $fingerprint = hash_file('sha256', $path);
            if ($fingerprint === false) {
                throw new BusinessRuleException('File impor tidak dapat dibaca. Unggah ulang file.');
            }
            foreach ($this->failures as &$failure) {
                if ($failure['line'] > 0 && blank($failure['values']['NO PESANAN'] ?? null) && blank($failure['values']['ID TRANSAKSI IMPOR'] ?? null)) {
                    $date = Spreadsheet::parseDate($failure['values']['TANGGAL'] ?? null) ?? '';
                    $failure['values']['ID TRANSAKSI IMPOR'] = hash('sha256', 'file|'.$fingerprint.'|date|'.$date.'|line|'.$failure['line']);
                }
            }
            unset($failure);
        } catch (BusinessRuleException $exception) {
            [$rows, $errors, $lineCount] = [[], [$exception->getMessage()], 0];
            $fingerprint = '';
            $this->fail(0, [], ImportFailureReason::FileFormat, $exception->getMessage());
        }

        return $this->completeBatch($batch, $rows, $errors, $lineCount, $options, $fingerprint);
    }

    /**
     * @param  array{warehouse_id: int, status: string}  $options
     * @return array{batch: SalesImportBatch, rows: int, created: int, skipped: int, fixed: int, errors: list<string>}
     */
    public function retryFailure(SalesImportFailure $failure, string $sku, array $options): array
    {
        return DB::transaction(function () use ($failure, $sku, $options): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $failure = SalesImportFailure::query()->lockForUpdate()->findOrFail($failure->id);
            if ($failure->status !== ImportFailureStatus::Open || $failure->line < 1) {
                throw new BusinessRuleException('Baris ini sudah ditangani. Muat ulang daftar impor gagal.');
            }

            $this->stores = $this->variants = $this->aliases = null;
            $this->failures = $this->fixed = [];
            $batch = SalesImportBatch::create([
                'source' => $failure->batch?->source ?? SalesImportSource::Manual,
                'file_name' => 'Perbaikan SKU baris #'.$failure->id,
                'imported_by' => auth()->id(),
            ]);
            $values = $failure->values;
            $values['SKU VARIAN'] = trim($sku);
            // Baris gagal lama yang belum memiliki identitas tetap aman dari percobaan ulang ganda.
            if (blank($values['ID TRANSAKSI IMPOR'] ?? null) && blank($values['NO PESANAN'] ?? null)) {
                $values['ID TRANSAKSI IMPOR'] = hash('sha256', 'failure|'.$failure->id);
            }
            $input = [];
            foreach (self::COLUMNS as $label => $key) {
                $input[$key] = $values[$label] ?? null;
            }
            $input['failure_id'] = (string) $failure->id;
            $errors = [];
            $row = $this->parseRow($input, $failure->line, $errors);

            return $this->completeBatch($batch, $row === null ? [] : [$row], $errors, 1, $options, '');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $options
     */
    private function completeBatch(SalesImportBatch $batch, array $rows, array $errors, int $lineCount, array $options, string $fingerprint): array
    {
        if ($lineCount === 0 && $errors === []) {
            $errors[] = 'Tidak ada baris data yang bisa diimpor.';
            $this->fail(0, [], ImportFailureReason::FileFormat, 'Tidak ada baris data yang bisa diimpor');
        }

        [$created, $skipped] = $this->store($rows, $batch, $options, $errors, $fingerprint);

        $batch->update([
            'status' => $created === 0 && $errors !== [] ? ImportBatchStatus::Failed : ImportBatchStatus::Done,
            'row_count' => $lineCount,
            'error_count' => count($errors),
        ]);

        $fixed = $this->saveFailures($batch);

        return ['batch' => $batch, 'rows' => $lineCount, 'created' => $created, 'skipped' => $skipped, 'fixed' => $fixed, 'errors' => $errors];
    }

    /**
     * Simpan baris gagal batch ini, lalu perbarui status baris gagal lama yang diimport ulang lewat kolom ID GAGAL.
     *
     * @return int jumlah baris gagal lama yang kini sudah diperbaiki
     */
    private function saveFailures(SalesImportBatch $batch): int
    {
        foreach ($this->failures as $failure) {
            $batch->failures()->create([
                'line' => $failure['line'],
                'values' => $failure['values'],
                'reason' => $failure['reason'],
                'message' => $failure['message'],
                'retry_of_id' => $failure['retry_of'] !== null && SalesImportFailure::query()->whereKey($failure['retry_of'])->exists() ? $failure['retry_of'] : null,
            ]);
        }

        // Baris lama, baris asalnya, dan percobaan ulang lain untuk baris yang sama (mis. file yang sama diimport dua kali).
        $resolve = fn (array $ids, ImportFailureStatus $status): int => $ids === [] ? 0 : SalesImportFailure::query()
            ->where(fn ($query) => $query
                ->whereKey($ids = array_values(array_unique($ids)))
                ->orWhereIn('retry_of_id', $ids)
                ->orWhereIn('id', SalesImportFailure::query()->whereKey($ids)->whereNotNull('retry_of_id')->select('retry_of_id')))
            ->where('batch_id', '!=', $batch->id)
            ->whereIn('status', [ImportFailureStatus::Open, ImportFailureStatus::Retried])
            ->update(['status' => $status, 'resolved_batch_id' => $batch->id, 'resolved_at' => now()]);

        $fixed = $resolve($this->fixed, ImportFailureStatus::Fixed);
        $resolve(array_diff(array_filter(array_column($this->failures, 'retry_of')), $this->fixed), ImportFailureStatus::Retried);

        return $fixed;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fail(int $line, array $values, ImportFailureReason $reason, string $message, ?int $retryOf = null): void
    {
        $this->failures[] = ['line' => $line, 'values' => $values, 'reason' => $reason, 'message' => rtrim($message, '.').'.', 'retry_of' => $retryOf];
    }

    /**
     * Simpan tiap baris dalam transaksinya sendiri: baris valid tersimpan, baris error dilaporkan.
     * Kesamaan toko, tanggal, SKU, qty, dan pendapatan tidak menandakan transaksi yang sama.
     *
     * @param  list<array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float, raw: array<string, string>, failure_id: ?int}>  $rows
     * @param  array{warehouse_id: int, status: string}  $options
     * @param  list<string>  $errors
     * @return array{0: int, 1: int} [tersimpan, dilewati]
     */
    private function store(array $rows, SalesImportBatch $batch, array $options, array &$errors, string $fingerprint): array
    {
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $key = hash('sha256', $row['order_no'] !== ''
                ? 'order|'.$row['store']->id.'|'.$row['order_no']
                : 'file|'.$fingerprint.'|date|'.$row['order_date'].'|line|'.$row['line']);

            if ($row['import_key'] !== '') {
                $key = $row['import_key'];
            }
            $row['raw']['ID TRANSAKSI IMPOR'] = $key;

            try {
                $saved = DB::transaction(function () use ($row, $batch, $options, $key): bool {
                    DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['import:'.$key]);
                    $order = SalesOrder::query()->where('import_key', $key)->lockForUpdate()->first();

                    if ($order === null && $row['order_no'] !== '') {
                        $order = SalesOrder::query()->where('store_id', $row['store']->id)->where('order_no', $row['order_no'])->lockForUpdate()->first();
                        if ($order !== null && $order->import_batch_id === null) {
                            throw new BusinessRuleException('No pesanan sudah digunakan pesanan manual. Koreksi melalui dokumen asal.');
                        }
                    }

                    if ($order !== null) {
                        if ($order->status === SalesOrderStatus::Cancelled) {
                            throw new BusinessRuleException('Pesanan impor ini sudah dibatalkan. Gunakan nomor pesanan baru bila memang transaksi baru.');
                        }
                        $item = $order->items()->sole();
                        if ($order->store_id !== $row['store']->id || ($row['order_no'] !== '' && $order->order_no !== $row['order_no']) || $item->variant_id !== $row['variant_id'] || $order->order_date->toDateString() !== $row['order_date'] || $order->warehouse_id !== (int) $options['warehouse_id']) {
                            throw new BusinessRuleException('Identitas/gudang pesanan sudah berbeda. Periksa dokumen asal sebelum koreksi.');
                        }
                        $sameRevenue = $item->revenue === null ? $row['revenue'] === null
                            : ($row['revenue'] !== null && round((float) $item->revenue, 2) === round($row['revenue'], 2));
                        if ($item->qty === $row['qty'] && $sameRevenue) {
                            return false;
                        }
                        $this->orders->reviseImported($order, $row['qty'], $row['revenue']);

                        return true;
                    }

                    $this->orders->create([
                        'store_id' => $row['store']->id, 'warehouse_id' => $options['warehouse_id'],
                        'order_no' => $row['order_no'] ?: sprintf('IMP%d-%04d', $batch->id, $row['line']),
                        'order_date' => $row['order_date'], 'status' => $options['status'] ?? SalesOrderStatus::Completed->value,
                        'import_batch_id' => $batch->id, 'import_key' => $key,
                    ], [[
                        'variant_id' => $row['variant_id'], 'sku_raw' => $row['sku_raw'], 'qty' => $row['qty'], 'revenue' => $row['revenue'],
                    ]]);

                    return true;
                });
                $saved ? $created++ : $skipped++;
                if ($row['failure_id'] !== null) {
                    $this->fixed[] = $row['failure_id'];
                }
            } catch (BusinessRuleException $exception) {
                $errors[] = "Baris {$row['line']}: {$exception->getMessage()}";
                $reason = $exception instanceof MissingPriceException ? ImportFailureReason::PriceMissing : ImportFailureReason::BusinessRule;
                $this->fail($row['line'], $row['raw'], $reason, $exception->getMessage(), $row['failure_id']);
            } catch (Throwable $exception) {
                report($exception);
                $message = 'Gagal disimpan. Periksa data atau hubungi administrator dengan nomor batch '.$batch->id;
                $errors[] = "Baris {$row['line']}: {$message}.";
                $this->fail($row['line'], $row['raw'], ImportFailureReason::Database, $message, $row['failure_id']);
            }
        }

        return [$created, $skipped];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: int} [baris valid, error, jumlah baris data]
     */
    private function parse(string $path, string $fileName, ?string $orderDate): array
    {
        $rows = [];
        $errors = [];

        // Format manual (template) dicoba dulu, lalu format export BigSeller "Analisa Bisnis SKU Toko".
        $sheets = Spreadsheet::readSheets($path, $fileName, [
            'manual' => [
                'headers' => [
                    ...self::COLUMNS,
                    'SKU BIGSELLER' => 'sku_bigseller',
                    'NO. PESANAN' => 'order_no',
                    self::FAILURE_ID_COLUMN => 'failure_id',
                ],
                'required' => ['order_date', 'store', 'qty', ['sku', 'sku_bigseller']],
            ],
            'bigseller' => [
                'headers' => self::BIGSELLER_COLUMNS,
                'required' => ['store', 'sku_bigseller', 'qty'],
            ],
        ], firstSheetOnly: true);

        $lines = reset($sheets);

        if ($lines === false) {
            throw new BusinessRuleException('Header tidak ditemukan. Gunakan template (kolom TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, QTY) atau file export BigSeller (Nama Toko, Nomor SKU Toko, Penjualan Produk Valid, Dana Penjualan Produk Valid).');
        }

        if (array_key_first($sheets) === 'bigseller') {
            // Export BigSeller berisi semua SKU toko; SKU tanpa penjualan (qty 0) bukan pesanan.
            $lines = array_values(array_filter($lines, fn (array $line): bool => Spreadsheet::parseNumber($line['values']['qty'] ?? null) != 0));
        }

        foreach ($lines as $line) {
            if (($line['values']['order_date'] ?? null) === null || $line['values']['order_date'] === '') {
                $line['values']['order_date'] = $orderDate;
            }

            $parsed = $this->parseRow($line['values'], $line['line'], $errors);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        return [$rows, $errors, count($lines)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     * @return array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float, raw: array<string, string>, failure_id: ?int}|null
     */
    private function parseRow(array $input, int $line, array &$errors): ?array
    {
        /** @var array<string, ImportFailureReason> $rowErrors pesan => kategori */
        $rowErrors = [];
        $raw = array_map(fn (string $key): string => Spreadsheet::text($input[$key] ?? null), self::COLUMNS);
        $failureId = Spreadsheet::text($input['failure_id'] ?? null);
        $failureId = ctype_digit($failureId) ? (int) $failureId : null;

        $importKey = Spreadsheet::text($input['import_key'] ?? null);
        if ($importKey !== '' && ! preg_match('/^[a-f0-9]{64}$/', $importKey)) {
            $rowErrors['ID TRANSAKSI IMPOR tidak valid; gunakan file baris gagal asli'] = ImportFailureReason::BusinessRule;
        }
        if (mb_strlen(Spreadsheet::text($input['order_no'] ?? null)) > 60) {
            $rowErrors['NO PESANAN maksimal 60 karakter'] = ImportFailureReason::BusinessRule;
        }
        $date = Spreadsheet::parseDate($input['order_date'] ?? null);
        if ($date === null) {
            $rowErrors['TANGGAL kosong atau formatnya tidak dikenali'] = ImportFailureReason::InvalidDate;
        }

        $storeName = (string) ($input['store'] ?? '');
        $store = $this->stores()->get(Str::lower(trim($storeName)));
        if ($storeName === '') {
            $rowErrors['NAMA TOKO kosong'] = ImportFailureReason::StoreNotFound;
        } elseif ($store === null) {
            $rowErrors["toko \"{$storeName}\" tidak ditemukan"] = ImportFailureReason::StoreNotFound;
        } elseif (! $store->is_active) {
            $rowErrors["toko \"{$storeName}\" tidak aktif"] = ImportFailureReason::StoreInactive;
        }

        $sku = (string) ($input['sku'] ?? '');
        $skuBigSeller = (string) ($input['sku_bigseller'] ?? '');
        $variant = $this->resolveVariant($sku, $skuBigSeller);
        if ($sku === '' && $skuBigSeller === '') {
            $rowErrors['SKU VARIAN / SKU BIG SELLER kosong'] = ImportFailureReason::SkuNotFound;
        } elseif ($variant === null) {
            $rowErrors['SKU "'.($sku ?: $skuBigSeller).'" tidak ditemukan (cek master varian / pemetaan SKU)'] = ImportFailureReason::SkuNotFound;
        } elseif (! $variant->is_active) {
            $rowErrors["SKU \"{$variant->sku}\" tidak aktif"] = ImportFailureReason::SkuInactive;
        }

        $qty = Spreadsheet::parseNumber($input['qty'] ?? null);
        if ($qty === null || $qty < 1 || floor($qty) != $qty) {
            $rowErrors['QTY harus bilangan bulat minimal 1'] = ImportFailureReason::InvalidQty;
        }

        $revenueRaw = $input['revenue'] ?? null;
        $revenue = Spreadsheet::parseNumber($revenueRaw);
        if ($revenueRaw !== null && $revenueRaw !== '' && ($revenue === null || $revenue < 0)) {
            $rowErrors['PENDAPATAN TOKO harus angka >= 0'] = ImportFailureReason::InvalidRevenue;
        }

        if ($rowErrors !== []) {
            $message = implode('; ', array_keys($rowErrors));
            $errors[] = "Baris {$line}: {$message}.";
            // Kategori = masalah pertama; pesan memuat semua masalah di baris ini.
            $this->fail($line, $raw, reset($rowErrors), $message, $failureId);

            return null;
        }

        return [
            'line' => $line,
            'order_date' => $date,
            'store' => $store,
            'variant_id' => $variant->id,
            'sku_raw' => $skuBigSeller !== '' ? $skuBigSeller : $sku,
            'qty' => (int) $qty,
            'revenue' => $revenue,
            'raw' => $raw,
            'failure_id' => $failureId,
            'order_no' => Spreadsheet::text($input['order_no'] ?? null),
            'import_key' => $importKey,
        ];
    }

    private function resolveVariant(string $sku, string $skuBigSeller): ?ProductVariant
    {
        if ($sku !== '') {
            return $this->variants()->get(Str::lower($sku));
        }

        if ($skuBigSeller === '') {
            return null;
        }

        $variantId = $this->aliases()->get(Str::lower($skuBigSeller));

        return $variantId !== null
            ? $this->variants()->firstWhere('id', $variantId)
            : $this->variants()->get(Str::lower($skuBigSeller));
    }

    /** @return Collection<string, Store> */
    private function stores(): Collection
    {
        return $this->stores ??= Store::query()->get()->keyBy(fn (Store $store): string => Str::lower(trim($store->name)));
    }

    /** @return Collection<string, ProductVariant> */
    private function variants(): Collection
    {
        return $this->variants ??= ProductVariant::query()->get()->keyBy(fn (ProductVariant $variant): string => Str::lower(trim($variant->sku)));
    }

    /** @return Collection<string, int> */
    private function aliases(): Collection
    {
        return $this->aliases ??= SkuAlias::query()->get()->mapWithKeys(fn (SkuAlias $alias): array => [Str::lower(trim($alias->alias)) => $alias->variant_id]);
    }

    private function firstLine(Throwable $exception): string
    {
        return Str::limit(strtok($exception->getMessage(), "\n") ?: 'kesalahan database', 160);
    }
}
