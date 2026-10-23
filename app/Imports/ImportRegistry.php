<?php

namespace App\Imports;

use App\Models\SalesImportFailure;
use App\Services\CatalogImporter;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderSpreadsheet;
use App\Services\StoreImporter;
use App\Support\ApplicationAccess;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetCells;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Daftar kategori data untuk "Import Perlu Dicek": tiap importer di aplikasi = 1 kategori.
 * Untuk tiap kategori: label, izin, kolom (LABEL HEADER), coba ulang dari nilai yang diedit,
 * template perbaikan (baris gagal + ID GAGAL), dan import file yang sudah dibenarkan.
 */
final class ImportRegistry
{
    public const SALES_ORDERS = 'sales_orders';

    /** Jumlah baris gagal pesanan per transaksi saat coba ulang. */
    public const SALES_RETRY_CHUNK = 200;

    /** Importer 1 sheet (RowImporter), urut tampilan. */
    public const ROW_IMPORTERS = [
        SkuAliasImport::class,
        SalesReturnImport::class,
        GoodsReceiptImport::class,
        StockAdjustmentImport::class,
        GoodsStockCountImport::class,
        MaterialStockCountImport::class,
        ProductModelImport::class,
        ProductCategoryImport::class,
        ColorImport::class,
        SizeImport::class,
        MaterialTypeImport::class,
        LaborTypeImport::class,
        SupplierImport::class,
        WarehouseImport::class,
        TailorImport::class,
        TailorPaymentImport::class,
        OwnerImport::class,
        ResellerImport::class,
        ChannelImport::class,
        PriceTierImport::class,
        ExpenseImport::class,
        ExpenseCategoryImport::class,
        CashAccountImport::class,
        ResellerPaymentImport::class,
        StoreAdSpendImport::class,
    ];

    /** @var array<string, array{label: string, permission: string, labels: list<string>, system: list<string>, class?: class-string<RowImporter>, sheet?: string}>|null */
    private static ?array $categories = null;

    /**
     * @return array<string, array{label: string, permission: string, labels: list<string>, system: list<string>, class?: class-string<RowImporter>, sheet?: string}>
     */
    public static function all(): array
    {
        if (self::$categories !== null) {
            return self::$categories;
        }

        $categories = [
            self::SALES_ORDERS => [
                'label' => 'Pesanan',
                'permission' => 'kelola penjualan',
                'labels' => array_keys(SalesOrderImporter::COLUMNS),
                'system' => ['ID TRANSAKSI IMPOR'],
            ],
        ];

        foreach (CatalogImporter::SHEETS as $type => $sheet) {
            $categories[CatalogImporter::issueKey($type)] = [
                'label' => match ($type) {
                    'products' => 'Varian / SKU',
                    'materials' => 'Stok Bahan',
                    default => $sheet['title'],
                },
                'permission' => 'kelola katalog',
                'labels' => CatalogImporter::labels($type),
                'system' => array_keys($sheet['system'] ?? []),
                'sheet' => $type,
            ];
        }

        $categories[StoreImporter::ISSUE_KEY] = [
            'label' => 'Reseller & Toko',
            'permission' => 'kelola mitra',
            'labels' => [...array_keys(StoreImporter::COLUMNS), ...array_keys(StoreImporter::SYSTEM_COLUMNS)],
            'system' => array_keys(StoreImporter::SYSTEM_COLUMNS),
        ];

        foreach (self::ROW_IMPORTERS as $class) {
            $importer = app($class);
            $categories[$importer->key()] = [
                'label' => $importer->title(),
                'permission' => $importer->permission(),
                'labels' => array_keys($importer->columns()),
                'system' => $importer->systemLabels(),
                'class' => $class,
            ];
        }

        return self::$categories = $categories;
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::all()[$key]);
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? Str::headline($key);
    }

    /**
     * Kunci tab halaman (tanpa titik): catalog.products => catalog-products.
     */
    public static function tab(string $key): string
    {
        return str_replace('.', '-', $key);
    }

    public static function fromTab(?string $tab): ?string
    {
        $key = $tab === null ? null : str_replace('-', '.', $tab);

        return self::exists($key) ? $key : null;
    }

    /**
     * Kolom yang bisa diedit (header merah).
     *
     * @return list<string>
     */
    public static function editableLabels(string $key): array
    {
        $category = self::all()[$key];

        return array_values(array_diff($category['labels'], $category['system']));
    }

    public static function allows(string $key): bool
    {
        return self::exists($key) && ApplicationAccess::allows(self::all()[$key]['permission']);
    }

    /**
     * Kategori yang boleh dilihat user saat ini.
     *
     * @return list<string>
     */
    public static function accessibleKeys(): array
    {
        return array_values(array_filter(array_keys(self::all()), fn (string $key): bool => self::allows($key)));
    }

    /**
     * Coba ulang baris gagal 1 kategori (nilai hasil edit, atau nilai lama setelah master data dibenahi).
     *
     * @param  Collection<int, SalesImportFailure>  $failures
     * @param  array<int, array<string, mixed>>  $edited  ID => nilai baru per LABEL HEADER
     * @param  array<string, mixed>  $options  pesanan: warehouse_id, status
     * @return array{fixed: int, failed: int, errors: list<string>}
     */
    public static function retry(string $key, Collection $failures, array $edited = [], array $options = []): array
    {
        $failures = $failures->filter(fn (SalesImportFailure $failure): bool => $failure->line > 0)->values();
        $values = $failures->mapWithKeys(fn (SalesImportFailure $failure): array => [$failure->id => [...$failure->values, ...($edited[$failure->id] ?? [])]])->all();
        $rows = $failures->map(fn (SalesImportFailure $failure): array => ['line' => $failure->line, 'values' => $values[$failure->id], 'failure_id' => $failure->id])->all();
        $fileName = 'Perbaikan '.self::label($key).' ('.count($rows).' baris)';
        $category = self::all()[$key];

        if ($rows === []) {
            return ['fixed' => 0, 'failed' => 0, 'errors' => []];
        }

        $result = match (true) {
            // Coba ulang pesanan berjalan dalam 1 transaksi (mengunci baris gagal): dipecah per SALES_RETRY_CHUNK
            // baris agar ribuan baris tidak menghabiskan lock database.
            $key === self::SALES_ORDERS => ['errors' => collect($values)->chunk(self::SALES_RETRY_CHUNK, preserveKeys: true)
                ->flatMap(fn (Collection $chunk, int $index): array => app(SalesOrderImporter::class)->retry(
                    $chunk->all(), $options, count($values) > self::SALES_RETRY_CHUNK ? $fileName.' bagian '.($index + 1) : $fileName,
                )['errors'])
                ->values()->all()],
            $key === StoreImporter::ISSUE_KEY => app(StoreImporter::class)->importValues($rows, $fileName),
            isset($category['sheet']) => app(CatalogImporter::class)->importValues($category['sheet'], $rows, $fileName),
            default => app($category['class'])->importValues($rows, $fileName),
        };

        $fixed = SalesImportFailure::query()->whereKey(array_keys($values))->where('status', 'fixed')->count();

        return ['fixed' => $fixed, 'failed' => count($rows) - $fixed, 'errors' => $result['errors']];
    }

    /**
     * Template perbaikan kategori: layout impor (kolom merah) + ID GAGAL & alasan (hijau), terisi baris yang dipilih.
     *
     * @param  Builder<SalesImportFailure>  $failures
     */
    public static function fixTemplate(string $key, Builder $failures): string
    {
        $category = self::all()[$key];
        $failures = $failures->clone()->where('importer', $key)->where('line', '>', 0)->reorder()->orderBy('id');

        return match (true) {
            $key === self::SALES_ORDERS => app(SalesOrderSpreadsheet::class)->failures($failures),
            isset($category['class']) => app($category['class'])->fixTemplate($failures->get()),
            default => self::genericTemplate($category, $failures->get()),
        };
    }

    /**
     * Import file yang sudah dibenarkan untuk 1 kategori.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> hasil importer (berisi errors)
     */
    public static function import(string $key, string $path, string $fileName, array $options = []): array
    {
        $category = self::all()[$key];

        if ($key === self::SALES_ORDERS) {
            return app(SalesOrderImporter::class)->importMany([['path' => $path, 'name' => $fileName]], $options);
        }

        if ($key === StoreImporter::ISSUE_KEY) {
            return app(StoreImporter::class)->import($path, $fileName);
        }

        if (isset($category['sheet'])) {
            return app(CatalogImporter::class)->import($path, $fileName, [$category['sheet']]);
        }

        $importer = app($category['class']);
        $public = get_object_vars($importer);

        foreach ($options as $option => $value) {
            if (array_key_exists($option, $public)) {
                $importer->{$option} = $value;
            }
        }

        return $importer->import($path, $fileName);
    }

    /**
     * @param  array{label: string, labels: list<string>, system: list<string>}  $category
     * @param  Collection<int, SalesImportFailure>  $failures
     */
    private static function genericTemplate(array $category, Collection $failures): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(Spreadsheet::sheetName($category['label']));
        $columns = [...$category['labels'], ...ImportIssueLog::FIX_COLUMNS];

        foreach ($columns as $index => $label) {
            $sheet->setColumnWidth(in_array($label, ['ALASAN GAGAL', 'CARA MEMPERBAIKI'], true) ? 60 : max(12, min(36, mb_strlen($label) + 6)), $index + 1);
        }

        $writer->addRow(Spreadsheet::headerRow([
            ...collect($category['labels'])->mapWithKeys(fn (string $label): array => [$label => ! in_array($label, $category['system'], true)])->all(),
            ...array_fill_keys(ImportIssueLog::FIX_COLUMNS, false),
        ]));

        foreach ($failures as $failure) {
            $writer->addRow(SpreadsheetCells::row([
                ...array_map(fn (string $label): ?string => $failure->value($label), $category['labels']),
                $failure->id, $failure->reason->getLabel(), $failure->message, $failure->reason->hint(), $failure->file_name, $failure->line,
            ]));
        }

        if ($failures->isNotEmpty()) {
            $sheet->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, $failures->count() + 1));
        }

        self::instructions($writer);
        $writer->close();

        return $path;
    }

    public static function instructions(XlsxWriter $writer): void
    {
        Spreadsheet::addSheet($writer, 'Petunjuk', [24, 100], [
            ['LANGKAH', 'KETERANGAN'],
            ['1. Perbaiki', 'Ubah kolom header MERAH sesuai ALASAN GAGAL dan CARA MEMPERBAIKI. Bila masalahnya di master data, perbaiki dulu di menunya lalu biarkan baris apa adanya.'],
            ['2. Import', 'Import file ini lewat tombol "Import data yang benar" di Import Perlu Dicek (tab kategori ini), atau lewat Import di halaman datanya. Kolom ID GAGAL jangan diubah.'],
            ['3. Hasil', 'Baris yang berhasil otomatis berstatus "Sudah diperbaiki". Baris yang gagal lagi muncul sebagai baris baru dengan alasan terbaru.'],
            ['Hapus baris', 'Baris yang memang tidak perlu diimport hapus dari file, lalu tandai "Abaikan" di Import Perlu Dicek.'],
        ]);
    }
}
