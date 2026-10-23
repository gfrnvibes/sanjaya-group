<?php

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\MissingPriceException;
use App\Imports\ImportIssueLog;
use App\Imports\ImportRegistry;
use App\Models\ProductVariant;
use App\Models\SalesImportBatch;
use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SkuAlias;
use App\Models\Store;
use App\Support\Spreadsheet;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor pesanan: 1 baris = 1 pesanan (1 produk). Format file mengikuti Sumber Data (SalesImportSource):
 *  - BigSeller: export pesanan BigSeller; Website: template dengan NO PESANAN. Nomor pesanan diambil dari file,
 *    1 nomor pesanan boleh berisi beberapa baris produk (dikenali per nomor + SKU + urutan SKU yang sama);
 *  - Manual / Shopee / TikTok / Lazada: template tanpa nomor pesanan; nomor dibuat otomatis.
 * File baris gagal (kolom ID GAGAL) selalu bisa diimport ulang apa pun sumbernya.
 * Header dicari otomatis (boleh ada judul di atasnya & kolom otomatis di sampingnya).
 * Tiap baris disimpan terpisah: baris valid tersimpan, baris error dilaporkan.
 * Nomor/ID transaksi mengenali pesanan lintas file; sidik isi file dan nomor baris mengenali unggahan ulang.
 * Status kosong = Diproses (barang dikirim, belum pasti selesai/retur); import ulang dengan status Selesai
 * menyelesaikan pesanan Diproses yang sama.
 * SKU yang belum dikenal dipetakan otomatis (opsi auto_map) bila penalaran SKU + nama produk + nama variasi cukup yakin.
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
        'NAMA PRODUK' => 'product_name', // info untuk pencocokan SKU (opsional)
        'NAMA VARIASI' => 'variation_name', // info untuk pencocokan SKU (opsional, mis. "Hitam,2XL")
    ];

    /**
     * Minimal total qty per SKU per pesanan agar produk bordir dihitung harga grosir (tingkat harga sesuai menu Harga Grosir Bordir).
     * Total = jumlah semua baris SKU yang sama (warna + ukuran sama) dalam 1 nomor pesanan; beda warna / ukuran tidak digabung.
     * Tanpa nomor pesanan, 1 baris = 1 pesanan.
     */
    public const WHOLESALE_MIN_QTY = 10;

    /** Template Manual / Shopee / TikTok / Lazada: tanpa nomor pesanan (kolom NO PESANAN diabaikan). */
    public const MANUAL_COLUMNS = [
        'TANGGAL' => 'order_date',
        'NAMA TOKO' => 'store',
        'SKU VARIAN' => 'sku',
        'SKU TOKO' => 'sku_bigseller',
        'SKU BIG SELLER' => 'sku_bigseller',
        'SKU BIGSELLER' => 'sku_bigseller',
        'SKU MARKETPLACE' => 'sku_bigseller',
        'NAMA PRODUK' => 'product_name',
        'NAMA VARIASI' => 'variation_name',
        'QTY' => 'qty',
        'PENDAPATAN TOKO' => 'revenue',
    ];

    /** Template Website: nomor pesanan wajib. SKU = SKU di website (dipetakan seperti SKU marketplace). */
    public const WEBSITE_COLUMNS = [
        'TANGGAL' => 'order_date',
        'NAMA TOKO' => 'store',
        'NO PESANAN' => 'order_no',
        'NO. PESANAN' => 'order_no',
        'NOMOR PESANAN' => 'order_no',
        'NAMA PRODUK' => 'product_name',
        'SKU' => 'sku_bigseller',
        'SKU VARIAN' => 'sku',
        'NAMA VARIASI' => 'variation_name',
        'QTY' => 'qty',
        'JUMLAH' => 'qty',
        'PENDAPATAN TOKO' => 'revenue',
    ];

    /** Header export pesanan BigSeller => kunci. 1 baris = 1 produk dalam pesanan. */
    public const BIGSELLER_COLUMNS = [
        'NOMOR PESANAN' => 'order_no',
        'NAMA PANGGILAN TOKO BIGSELLER' => 'store',
        'NAMA TOKO' => 'store',
        'WAKTU PESANAN DIBUAT' => 'order_date',
        'NAMA PRODUK' => 'product_name',
        'SKU' => 'sku_bigseller',
        'NAMA VARIASI' => 'variation_name',
        'JUMLAH' => 'qty',
        'SUBTOTAL PRODUK' => 'revenue',
    ];

    /** Kolom ID baris gagal (terisi di file baris gagal, kosong di file biasa). */
    public const FAILURE_ID_COLUMN = 'ID GAGAL';

    /** @var list<array{line: int, values: array<string, string>, reason: ImportFailureReason, message: string, retry_of: ?int}> */
    private array $failures = [];

    /** @var list<int> ID baris gagal lama yang berhasil diimport ulang */
    private array $fixed = [];

    /** @var array<string, string> SKU file => SKU varian yang dipetakan otomatis saat impor */
    private array $mapped = [];

    /** @var array{warehouse_id?: int, status?: ?string, source?: string, order_date?: ?string, auto_map?: bool} */
    private array $options = [];

    public function __construct(
        private SalesOrderService $orders,
        private SupplierBilling $billing,
        private SkuMatcher $matcher,
        private SkuMapping $mapping,
    ) {}

    /**
     * Impor beberapa file sekaligus. Tiap file = 1 batch impor; pesanan dengan nomor/ID yang sama
     * dikenali kembali. Dua baris identik dalam satu file tetap menjadi dua pesanan terpisah.
     *
     * @param  list<array{path: string, name: string}>  $files
     * @param  array{warehouse_id: int, status: ?string, source: string, order_date?: ?string, auto_map?: bool}  $options
     * @return array{batches: list<SalesImportBatch>, files: int, rows: int, created: int, skipped: int, fixed: int, errors: list<string>, mapped: array<string, string>}
     */
    public function importMany(array $files, array $options): array
    {
        $total = ['batches' => [], 'files' => count($files), 'rows' => 0, 'created' => 0, 'skipped' => 0, 'fixed' => 0, 'errors' => [], 'mapped' => []];

        foreach ($files as $file) {
            $result = $this->import($file['path'], $file['name'], $options);

            $total['batches'][] = $result['batch'];
            $total['rows'] += $result['rows'];
            $total['created'] += $result['created'];
            $total['skipped'] += $result['skipped'];
            $total['fixed'] += $result['fixed'];
            $total['mapped'] += $result['mapped'];

            foreach ($result['errors'] as $error) {
                $total['errors'][] = count($files) > 1 ? "{$file['name']} — {$error}" : $error;
            }
        }

        return $total;
    }

    /**
     * @param  array{warehouse_id: int, status: ?string, source: string, order_date?: ?string, auto_map?: bool}  $options
     * @return array{batch: SalesImportBatch, rows: int, created: int, skipped: int, fixed: int, errors: list<string>, mapped: array<string, string>}
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
        $this->failures = $this->fixed = $this->mapped = [];
        $this->options = $options;

        try {
            $fingerprint = is_file($path) ? hash_file('sha256', $path) : false;
            if ($fingerprint === false) {
                throw new BusinessRuleException('File impor tidak dapat dibaca. Unggah ulang file.');
            }
            [$rows, $errors, $lineCount] = $this->parse($path, $fileName, $fingerprint);
        } catch (BusinessRuleException $exception) {
            [$rows, $errors, $lineCount] = [[], [$exception->getMessage()], 0];
            $fingerprint = '';
            $this->fail(0, [], ImportFailureReason::FileFormat, $exception->getMessage());
        }

        return $this->completeBatch($batch, $rows, $errors, $lineCount, $options, $fingerprint);
    }

    /**
     * @param  array{warehouse_id: int, status: ?string}  $options
     * @return array{batch: SalesImportBatch, rows: int, created: int, skipped: int, fixed: int, errors: list<string>, mapped: array<string, string>}
     */
    public function retryFailure(SalesImportFailure $failure, string $sku, array $options): array
    {
        return $this->retry([$failure->id => [...$failure->values, 'SKU VARIAN' => trim($sku)]], $options, 'Perbaikan SKU baris #'.$failure->id);
    }

    /**
     * Coba ulang baris gagal (nilai hasil edit langsung, atau nilai lama setelah master data diperbaiki)
     * dalam 1 batch impor. Baris yang sudah ditangani (bukan "Belum diperbaiki") ditolak.
     *
     * @param  array<int, array<string, mixed>>  $values  ID baris gagal => nilai per LABEL HEADER
     * @param  array{warehouse_id: int, status: ?string}  $options
     * @return array{batch: SalesImportBatch, rows: int, created: int, skipped: int, fixed: int, errors: list<string>, mapped: array<string, string>}
     */
    public function retry(array $values, array $options, ?string $fileName = null): array
    {
        return DB::transaction(function () use ($values, $options, $fileName): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $failures = SalesImportFailure::query()->lockForUpdate()->whereKey(array_keys($values))->orderBy('id')->get();

            if ($failures->count() !== count($values) || $failures->contains(fn (SalesImportFailure $failure): bool => $failure->status !== ImportFailureStatus::Open || $failure->line < 1)) {
                throw new BusinessRuleException('Baris ini sudah ditangani. Muat ulang daftar Import Perlu Dicek.');
            }

            $this->stores = $this->variants = $this->aliases = null;
            $this->failures = $this->fixed = $this->mapped = [];
            $this->options = $options;
            $batch = SalesImportBatch::create([
                'source' => $failures->first()->batch?->source ?? SalesImportSource::Manual,
                'file_name' => $fileName ?? 'Perbaikan '.$failures->count().' baris',
                'imported_by' => auth()->id(),
            ]);
            $rows = [];
            $errors = [];

            foreach ($failures as $index => $failure) {
                $row = array_map(fn ($value): string => trim((string) $value), $values[$failure->id]);
                // Baris gagal lama yang belum memiliki identitas tetap aman dari percobaan ulang ganda.
                if (blank($row['ID TRANSAKSI IMPOR'] ?? null) && blank($row['NO PESANAN'] ?? null)) {
                    $row['ID TRANSAKSI IMPOR'] = hash('sha256', 'failure|'.$failure->id);
                }
                $input = ['failure_id' => (string) $failure->id];
                foreach (self::COLUMNS as $label => $key) {
                    $input[$key] = $row[$label] ?? null;
                }
                $parsed = $this->parseRow($input, $failure->line, $errors);

                if ($parsed !== null) {
                    $rows[] = [...$parsed, 'seq' => $index + 1];
                }
            }

            return $this->completeBatch($batch, $rows, $errors, $failures->count(), $options, '');
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

        return ['batch' => $batch, 'rows' => $lineCount, 'created' => $created, 'skipped' => $skipped, 'fixed' => $fixed, 'errors' => $errors, 'mapped' => $this->mapped];
    }

    /**
     * Simpan baris gagal batch ini, lalu perbarui status baris gagal lama yang diimport ulang lewat kolom ID GAGAL.
     *
     * @return int jumlah baris gagal lama yang kini sudah diperbaiki
     */
    private function saveFailures(SalesImportBatch $batch): int
    {
        $log = new ImportIssueLog(ImportRegistry::SALES_ORDERS, $batch->file_name);

        foreach ($this->failures as $failure) {
            $log->fail($failure['line'], $failure['values'], $failure['message'], $failure['reason'], $failure['retry_of']);
        }

        foreach ($this->fixed as $failureId) {
            $log->fixed($failureId);
        }

        return $log->save($batch->id);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fail(int $line, array $values, ImportFailureReason $reason, string $message, ?int $retryOf = null): void
    {
        $this->failures[] = ['line' => $line, 'values' => $values, 'reason' => $reason, 'message' => rtrim($message, '.').'.', 'retry_of' => $retryOf];
    }

    /**
     * Harga grosir bordir untuk baris ini: berlaku otomatis bila model punya harga grosir dan total qty produk
     * SKU ini dalam pesanannya (wholesale_qty) mencapai minimal qty-nya (mis. >= 10 / >= 20). Null = harga normal.
     *
     * @param  array<string, mixed>  $row
     */
    private function wholesalePrice(array $row): ?float
    {
        $variant = $this->variants()->firstWhere('id', $row['variant_id']);

        return $variant === null ? null : $this->wholesaleAt($variant, $row['wholesale_qty'] ?? $row['qty'], $row['order_date']);
    }

    private function wholesaleAt(ProductVariant $variant, int $orderQty, CarbonInterface|string $date): ?float
    {
        if ($orderQty < self::WHOLESALE_MIN_QTY || ! $this->billing->hasWholesale($variant)) {
            return null;
        }

        return $this->billing->wholesaleFor($variant, $orderQty, $date)['unit_price'] ?? null;
    }

    /**
     * Samakan harga grosir semua baris SKU yang sama dalam 1 nomor pesanan dengan total qty SKU itu yang tersimpan
     * (mis. koreksi lewat file lain, atau baris gagal yang baru diperbaiki).
     *
     * @param  array<string, array{0: int, 1: string, 2: int}>  $groups  grup => [store_id, order_no, variant_id]
     * @param  list<string>  $errors
     */
    private function reconcileWholesale(array $groups, array &$errors): void
    {
        foreach ($groups as [$storeId, $orderNo, $variantId]) {
            $items = SalesOrderItem::query()
                ->where('variant_id', $variantId)
                ->whereHas('salesOrder', fn ($query) => $query->where('store_id', $storeId)->where('order_no', $orderNo)
                    ->whereNotNull('import_batch_id')->where('status', '!=', SalesOrderStatus::Cancelled))
                ->with(['salesOrder', 'variant'])
                ->orderBy('id')
                ->get()
                ->filter(fn (SalesOrderItem $item): bool => $item->variant !== null && $this->billing->hasWholesale($item->variant));
            $total = (int) $items->sum('qty');

            foreach ($items as $item) {
                $price = $this->wholesaleAt($item->variant, $total, $item->salesOrder->order_date);
                $current = $item->is_consignment ? round((float) $item->reseller_unit_price, 2) : null;

                if ($current === ($price === null ? null : round($price, 2))) {
                    continue;
                }

                try {
                    $this->orders->reviseImported($item->salesOrder, $item->qty, $item->revenue === null ? null : (float) $item->revenue, $price);
                } catch (BusinessRuleException $exception) {
                    $errors[] = "Pesanan {$orderNo} SKU {$item->variant->sku}: harga grosir tidak bisa disesuaikan. {$exception->getMessage()}";
                }
            }
        }
    }

    /**
     * Simpan / koreksi 1 pesanan impor. True = tersimpan atau berubah, false = sudah ada dan sama.
     *
     * @param  array<string, mixed>  $row
     * @param  array{warehouse_id: int, status: ?string}  $options
     */
    private function savePart(array $row, SalesImportBatch $batch, array $options): bool
    {
        $key = $row['key'];
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['import:'.$key]);
        $order = SalesOrder::query()->where('import_key', $key)->lockForUpdate()->first();

        if ($order === null && $row['order_no'] !== '' && SalesOrder::query()->where('store_id', $row['store']->id)->where('order_no', $row['order_no'])->whereNull('import_batch_id')->exists()) {
            throw new BusinessRuleException('No pesanan sudah digunakan pesanan manual. Koreksi melalui dokumen asal.');
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
            $changed = false;
            if ($item->qty !== $row['qty'] || ! $sameRevenue) {
                // Qty berubah bisa membuat baris masuk / keluar harga grosir.
                $this->orders->reviseImported($order, $row['qty'], $row['revenue'], $this->wholesalePrice($row));
                $changed = true;
            }
            // Import ulang dengan status Selesai: pesanan yang masih Diproses (baru dikirim) menjadi Selesai.
            if (($options['status'] ?? null) === SalesOrderStatus::Completed->value && $order->status === SalesOrderStatus::Pending) {
                $this->orders->completePending($order);
                $changed = true;
            }

            return $changed;
        }

        $line = ['variant_id' => $row['variant_id'], 'sku_raw' => $row['sku_raw'], 'qty' => $row['qty'], 'revenue' => $row['revenue']];

        // Grosir bordir (QTY >= minimal harga grosir): titipan, harga ke reseller = harga supplier.
        if (($price = $this->wholesalePrice($row)) !== null) {
            $line['consignment_price'] = $price;
        }

        $this->orders->create([
            'store_id' => $row['store']->id, 'warehouse_id' => $options['warehouse_id'],
            'order_no' => $row['order_no'] ?: sprintf('IMP%d-%04d', $batch->id, $row['seq'] ?? $row['line']),
            'order_date' => $row['order_date'], 'status' => ($options['status'] ?? null) ?: SalesOrderStatus::Pending->value,
            'import_batch_id' => $batch->id, 'import_key' => $key,
        ], [$line]);

        return true;
    }

    /**
     * Simpan tiap baris dalam transaksinya sendiri: baris valid tersimpan, baris error dilaporkan.
     * Kesamaan toko, tanggal, SKU, qty, dan pendapatan tidak menandakan transaksi yang sama.
     *
     * @param  list<array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float, raw: array<string, string>, failure_id: ?int}>  $rows
     * @param  array{warehouse_id: int, status: ?string}  $options
     * @param  list<string>  $errors
     * @return array{0: int, 1: int} [tersimpan, dilewati]
     */
    private function store(array $rows, SalesImportBatch $batch, array $options, array &$errors, string $fingerprint): array
    {
        $created = 0;
        $skipped = 0;

        // Grosir bordir dihitung per SKU per pesanan: total qty SKU yang sama dalam 1 nomor pesanan di file ini.
        $groups = [];
        $wholesaleQty = [];
        foreach ($rows as $row) {
            if ($row['order_no'] === '') {
                continue;
            }
            $group = $row['store']->id.'|'.$row['order_no'].'|'.$row['variant_id'];
            $groups[$group] = [$row['store']->id, $row['order_no'], $row['variant_id']];
            $wholesaleQty[$group] = ($wholesaleQty[$group] ?? 0) + $row['qty'];
        }

        foreach ($rows as $row) {
            $row['wholesale_qty'] = $row['order_no'] === '' ? $row['qty'] : max($row['qty'], $wholesaleQty[$row['store']->id.'|'.$row['order_no'].'|'.$row['variant_id']] ?? 0);
            // Biasanya sudah terisi saat parse; baris gagal lama tanpa ID memakai nomor pesanan / posisi baris.
            $key = $row['import_key'] !== '' ? $row['import_key'] : ($row['order_no'] !== ''
                ? self::orderKey($row['store']->name, $row['order_no'], $row['sku_raw'], 1)
                : self::fileKey($fingerprint, $row['order_date'], $row['line']));
            $row['raw']['ID TRANSAKSI IMPOR'] = $key;

            try {
                $saved = DB::transaction(fn (): bool => $this->savePart([...$row, 'key' => $key], $batch, $options));
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

        $this->reconcileWholesale($groups, $errors);

        return [$created, $skipped];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: int} [baris valid, error, jumlah baris data]
     */
    private function parse(string $path, string $fileName, string $fingerprint): array
    {
        $rows = [];
        $errors = [];
        $source = SalesImportSource::tryFrom((string) ($this->options['source'] ?? '')) ?? SalesImportSource::Manual;

        // File baris gagal dicoba dulu (bisa dari sumber mana pun), lalu format sesuai Sumber Data.
        $sheets = Spreadsheet::readSheets($path, $fileName, [
            'failures' => [
                'headers' => [
                    ...self::COLUMNS,
                    'SKU BIGSELLER' => 'sku_bigseller',
                    'NO. PESANAN' => 'order_no',
                    self::FAILURE_ID_COLUMN => 'failure_id',
                ],
                'required' => ['failure_id', 'store', 'qty', ['sku', 'sku_bigseller']],
            ],
            $source->value => match ($source) {
                SalesImportSource::BigSeller => ['headers' => self::BIGSELLER_COLUMNS, 'required' => ['order_no', 'store', 'sku_bigseller', 'qty']],
                SalesImportSource::Website => ['headers' => self::WEBSITE_COLUMNS, 'required' => ['order_date', 'store', 'order_no', 'qty', ['sku', 'sku_bigseller']]],
                default => ['headers' => self::MANUAL_COLUMNS, 'required' => ['order_date', 'store', 'qty', ['sku', 'sku_bigseller']]],
            },
        ], firstSheetOnly: true);

        $lines = reset($sheets);

        if ($lines === false) {
            throw new BusinessRuleException('Header tidak sesuai format '.$source->getLabel().'. '.$source->formatHint());
        }

        /** @var array<string, int> $occurrences toko + nomor pesanan + SKU => urutan baris SKU yang sama dalam pesanan */
        $occurrences = [];

        foreach ($lines as $line) {
            $values = $line['values'];
            if (blank($values['order_date'] ?? null)) {
                $values['order_date'] = $this->options['order_date'] ?? null;
            }

            // Identitas baris: nomor pesanan + SKU (+ urutan bila SKU sama muncul lagi di pesanan yang sama),
            // atau sidik file + posisi baris untuk file tanpa nomor pesanan.
            if (blank($values['import_key'] ?? null)) {
                $store = Spreadsheet::text($values['store'] ?? null);
                $orderNo = Spreadsheet::text($values['order_no'] ?? null);
                $skuRaw = Spreadsheet::text($values['sku_bigseller'] ?? null) ?: Spreadsheet::text($values['sku'] ?? null);

                if ($orderNo !== '') {
                    $group = Str::lower(trim($store)).'|'.$orderNo.'|'.Str::lower($skuRaw);
                    $occurrences[$group] = ($occurrences[$group] ?? 0) + 1;
                    $values['import_key'] = self::orderKey($store, $orderNo, $skuRaw, $occurrences[$group]);
                } else {
                    $values['import_key'] = self::fileKey($fingerprint, Spreadsheet::parseDate($values['order_date'] ?? null) ?? '', $line['line']);
                }
            }

            $parsed = $this->parseRow($values, $line['line'], $errors);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        return [$rows, $errors, count($lines)];
    }

    private static function orderKey(string $store, string $orderNo, ?string $skuRaw, int $occurrence): string
    {
        return hash('sha256', 'order|'.Str::lower(trim($store)).'|'.$orderNo.'|'.Str::lower(trim((string) $skuRaw)).'|'.$occurrence);
    }

    private static function fileKey(string $fingerprint, string $date, int $line): string
    {
        return hash('sha256', 'file|'.$fingerprint.'|date|'.$date.'|line|'.$line);
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

        $sku = Spreadsheet::text($input['sku'] ?? null);
        $skuBigSeller = Spreadsheet::text($input['sku_bigseller'] ?? null);
        $variant = $this->resolveVariant($sku, $skuBigSeller, Spreadsheet::text($input['product_name'] ?? null), Spreadsheet::text($input['variation_name'] ?? null));
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

    /**
     * SKU VARIAN = SKU sistem. SKU marketplace / website: pemetaan SKU atau SKU sistem, juga tanpa keterangan
     * dalam kurung ("BC22-Hitam-2XL (LD 118 cm)"); bila belum dikenal dan opsi auto_map aktif, penalaran SKU +
     * nama produk + nama variasi yang cukup yakin langsung disimpan sebagai pemetaan SKU baru.
     */
    private function resolveVariant(string $sku, string $skuRaw, string $productName = '', string $variation = ''): ?ProductVariant
    {
        if ($sku !== '') {
            return $this->variants()->get(Str::lower($sku));
        }

        if ($skuRaw === '') {
            return null;
        }

        foreach (array_unique([$skuRaw, SkuMatcher::cleanSku($skuRaw)]) as $candidate) {
            $variantId = $this->aliases()->get(Str::lower($candidate));
            $variant = $variantId !== null
                ? $this->variants()->firstWhere('id', $variantId)
                : $this->variants()->get(Str::lower($candidate));

            if ($variant !== null) {
                return $variant;
            }
        }

        if (! ($this->options['auto_map'] ?? false) || ($productName === '' && $variation === '')) {
            return null;
        }

        $best = $this->matcher->best($skuRaw, $productName, SkuMatcher::AUTO_MAP, $variation);
        if ($best === null) {
            return null;
        }

        $source = SalesImportSource::tryFrom((string) ($this->options['source'] ?? '')) ?? SalesImportSource::Manual;
        try {
            $this->mapping->map($skuRaw, $best['variant'], SkuAlias::sourceFor($source));
        } catch (BusinessRuleException) {
            return null;
        }

        $this->aliases()->put(Str::lower($skuRaw), $best['variant']->id);
        $this->mapped[$skuRaw] = $best['variant']->sku;

        return $this->variants()->firstWhere('id', $best['variant']->id);
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
