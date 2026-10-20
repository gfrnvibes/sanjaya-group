<?php

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\SalesImportBatch;
use App\Models\SalesOrderItem;
use App\Models\SkuAlias;
use App\Models\Store;
use App\Support\Spreadsheet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor sheet "INPUT ORDERAN RESELLER": 1 baris = 1 pesanan.
 * Header dicari otomatis (boleh ada judul di atasnya & kolom otomatis di sampingnya).
 * Tiap baris disimpan terpisah: baris valid tersimpan, baris error dilaporkan.
 * Baris yang sama persis dengan pesanan yang sudah ada (toko, tanggal, SKU, qty, pendapatan) dilewati agar tidak dobel.
 * Bisa beberapa file sekaligus (importMany): 1 file = 1 batch impor.
 */
class SalesOrderImporter
{
    /** @var Collection<string, Store>|null */
    private ?Collection $stores = null;

    /** @var Collection<string, ProductVariant>|null */
    private ?Collection $variants = null;

    /** @var Collection<string, int>|null */
    private ?Collection $aliases = null;

    public function __construct(private SalesOrderService $orders) {}

    /**
     * Impor beberapa file sekaligus. Tiap file = 1 batch impor; file diproses berurutan sehingga baris
     * yang sama persis dengan file sebelumnya (atau pesanan yang sudah ada) dilewati.
     *
     * @param  list<array{path: string, name: string}>  $files
     * @param  array{warehouse_id: int, status: string, source: string}  $options
     * @return array{batches: list<SalesImportBatch>, files: int, rows: int, created: int, skipped: int, errors: list<string>}
     */
    public function importMany(array $files, array $options): array
    {
        $total = ['batches' => [], 'files' => count($files), 'rows' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($files as $file) {
            $result = $this->import($file['path'], $file['name'], $options);

            $total['batches'][] = $result['batch'];
            $total['rows'] += $result['rows'];
            $total['created'] += $result['created'];
            $total['skipped'] += $result['skipped'];

            foreach ($result['errors'] as $error) {
                $total['errors'][] = count($files) > 1 ? "{$file['name']} — {$error}" : $error;
            }
        }

        return $total;
    }

    /**
     * @param  array{warehouse_id: int, status: string, source: string}  $options
     * @return array{batch: SalesImportBatch, rows: int, created: int, skipped: int, errors: list<string>}
     */
    public function import(string $path, string $fileName, array $options): array
    {
        $batch = SalesImportBatch::create([
            'source' => $options['source'] ?? SalesImportSource::Manual->value,
            'file_name' => $fileName,
            'status' => ImportBatchStatus::Pending,
            'imported_by' => auth()->id(),
        ]);

        try {
            [$rows, $errors, $lineCount] = $this->parse($path, $fileName);
        } catch (BusinessRuleException $exception) {
            [$rows, $errors, $lineCount] = [[], [$exception->getMessage()], 0];
        }

        if ($lineCount === 0 && $errors === []) {
            $errors[] = 'Tidak ada baris data yang bisa diimpor.';
        }

        [$created, $skipped] = $this->store($rows, $batch, $options, $errors);

        $batch->update([
            'status' => $created === 0 && $errors !== [] ? ImportBatchStatus::Failed : ImportBatchStatus::Done,
            'row_count' => $lineCount,
            'error_count' => count($errors),
        ]);

        return ['batch' => $batch, 'rows' => $lineCount, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Simpan tiap baris dalam transaksinya sendiri: baris valid tersimpan, baris error dilaporkan.
     * Baris yang sama persis dengan pesanan yang sudah ada (toko, tanggal, SKU, qty, pendapatan) dilewati.
     *
     * @param  list<array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float}>  $rows
     * @param  array{warehouse_id: int, status: string}  $options
     * @param  list<string>  $errors
     * @return array{0: int, 1: int} [tersimpan, dilewati]
     */
    private function store(array $rows, SalesImportBatch $batch, array $options, array &$errors): array
    {
        $created = 0;
        $skipped = 0;

        /** @var array<string, int> sisa pesanan yang sudah ada per sidik baris */
        $existing = [];

        foreach ($rows as $row) {
            $key = $this->signature($row);
            $existing[$key] ??= $this->existingCount($row);

            // Baris identik dalam satu file tetap diimpor sebanyak yang belum ada di sistem.
            if ($existing[$key] > 0) {
                $existing[$key]--;
                $skipped++;

                continue;
            }

            try {
                DB::transaction(fn () => $this->orders->create([
                    'store_id' => $row['store']->id,
                    'warehouse_id' => $options['warehouse_id'],
                    'order_no' => sprintf('IMP%d-%04d', $batch->id, $row['line']),
                    'order_date' => $row['order_date'],
                    'status' => $options['status'] ?? SalesOrderStatus::Completed->value,
                    'import_batch_id' => $batch->id,
                ], [[
                    'variant_id' => $row['variant_id'],
                    'sku_raw' => $row['sku_raw'],
                    'qty' => $row['qty'],
                    'revenue' => $row['revenue'],
                ]]));
                $created++;
            } catch (BusinessRuleException $exception) {
                $errors[] = "Baris {$row['line']}: {$exception->getMessage()}";
            } catch (QueryException $exception) {
                $errors[] = "Baris {$row['line']}: gagal disimpan ({$this->firstLine($exception)}).";
            }
        }

        return [$created, $skipped];
    }

    /**
     * @param  array{order_date: string, store: Store, variant_id: int, qty: int, revenue: ?float}  $row
     */
    private function signature(array $row): string
    {
        return implode('|', [
            $row['store']->id, $row['order_date'], $row['variant_id'], $row['qty'],
            $row['revenue'] === null ? '-' : number_format($row['revenue'], 2, '.', ''),
        ]);
    }

    /**
     * Jumlah baris pesanan yang sudah ada dengan toko, tanggal, SKU, qty, dan pendapatan yang sama.
     *
     * @param  array{order_date: string, store: Store, variant_id: int, qty: int, revenue: ?float}  $row
     */
    private function existingCount(array $row): int
    {
        return SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.store_id', $row['store']->id)
            ->whereDate('sales_orders.order_date', $row['order_date'])
            ->where('sales_order_items.variant_id', $row['variant_id'])
            ->where('sales_order_items.qty', $row['qty'])
            ->when(
                $row['revenue'] === null,
                fn ($query) => $query->whereNull('sales_order_items.revenue'),
                fn ($query) => $query->where('sales_order_items.revenue', round($row['revenue'], 2)),
            )
            ->count();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: int} [baris valid, error, jumlah baris data]
     */
    private function parse(string $path, string $fileName): array
    {
        $rows = [];
        $errors = [];

        $lines = Spreadsheet::read($path, $fileName, [
            'TANGGAL' => 'order_date',
            'NAMA TOKO' => 'store',
            'SKU VARIAN' => 'sku',
            'SKU BIG SELLER' => 'sku_bigseller',
            'SKU BIGSELLER' => 'sku_bigseller',
            'QTY' => 'qty',
            'PENDAPATAN TOKO' => 'revenue',
        ], ['order_date', 'store', 'qty', ['sku', 'sku_bigseller']],
            'Header tidak ditemukan. Pastikan ada kolom TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, dan QTY (gunakan template).');

        foreach ($lines as $line) {
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
     * @return array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float}|null
     */
    private function parseRow(array $input, int $line, array &$errors): ?array
    {
        $rowErrors = [];

        $date = Spreadsheet::parseDate($input['order_date'] ?? null);
        if ($date === null) {
            $rowErrors[] = 'TANGGAL kosong atau formatnya tidak dikenali';
        }

        $storeName = (string) ($input['store'] ?? '');
        $store = $this->stores()->get(Str::lower($storeName));
        if ($storeName === '') {
            $rowErrors[] = 'NAMA TOKO kosong';
        } elseif ($store === null) {
            $rowErrors[] = "toko \"{$storeName}\" tidak ditemukan";
        } elseif (! $store->is_active) {
            $rowErrors[] = "toko \"{$storeName}\" tidak aktif";
        }

        $sku = (string) ($input['sku'] ?? '');
        $skuBigSeller = (string) ($input['sku_bigseller'] ?? '');
        $variant = $this->resolveVariant($sku, $skuBigSeller);
        if ($sku === '' && $skuBigSeller === '') {
            $rowErrors[] = 'SKU VARIAN / SKU BIG SELLER kosong';
        } elseif ($variant === null) {
            $rowErrors[] = 'SKU "'.($sku ?: $skuBigSeller).'" tidak ditemukan (cek master varian / pemetaan SKU)';
        } elseif (! $variant->is_active) {
            $rowErrors[] = "SKU \"{$variant->sku}\" tidak aktif";
        }

        $qty = Spreadsheet::parseNumber($input['qty'] ?? null);
        if ($qty === null || $qty < 1 || floor($qty) != $qty) {
            $rowErrors[] = 'QTY harus bilangan bulat minimal 1';
        }

        $revenueRaw = $input['revenue'] ?? null;
        $revenue = Spreadsheet::parseNumber($revenueRaw);
        if ($revenueRaw !== null && $revenueRaw !== '' && ($revenue === null || $revenue < 0)) {
            $rowErrors[] = 'PENDAPATAN TOKO harus angka >= 0';
        }

        if ($rowErrors !== []) {
            $errors[] = "Baris {$line}: ".implode('; ', $rowErrors).'.';

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
