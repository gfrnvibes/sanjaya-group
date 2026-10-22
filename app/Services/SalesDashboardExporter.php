<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ProductCategory;
use App\Models\Reseller;
use App\Models\Store;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetCells;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Export Dashboard Produk (semua bagian, sesuai pengaturan aktif) ke workbook multi-sheet.
 */
class SalesDashboardExporter
{
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'ods' => 'OpenDocument (.ods)'];

    public function __construct(private SalesDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function export(array $params, string $format = 'xlsx'): string
    {
        $d = $this->dashboard->build($params);
        $p = $d['params'];
        $r = $d['returns'];
        $path = Spreadsheet::tempPath($format);
        $writer = $format === 'ods' ? new OdsWriter : new XlsxWriter;
        $writer->openToFile($path);

        $money = (new Style)->setFormat('#,##0');
        $decimal = (new Style)->setFormat('#,##0.0');
        $percent = (new Style)->setFormat('0.0%');
        $daysOf = fn ($value): ?int => $value === null ? null : (int) floor($value);
        $filterText = collect([
            'Toko' => [Store::class, $params['store_ids'] ?? []],
            'Channel' => [Channel::class, $params['channel_ids'] ?? []],
            'Reseller' => [Reseller::class, $params['reseller_ids'] ?? []],
            'Kategori' => [ProductCategory::class, $params['category_ids'] ?? []],
        ])->filter(fn (array $filter): bool => filled($filter[1]))
            ->map(fn (array $filter, string $label): string => $label.': '.$filter[0]::query()->whereIn('id', (array) $filter[1])->pluck('name')->implode(', '))
            ->implode(' · ') ?: 'Semua toko & produk';

        // Ringkasan
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Ringkasan');
        $sheet->setColumnWidth(38, 1);
        $sheet->setColumnWidth(22, 2);
        $writer->addRow(SpreadsheetCells::row(['DASHBOARD PRODUK TERLARIS & PRIORITAS PRODUKSI'], (new Style)->setFontBold()->setFontSize(13)));
        $writer->addRow(SpreadsheetCells::row(['Dibuat', now()->format('Y-m-d H:i')]));
        $writer->addRow(SpreadsheetCells::row([]));
        foreach ([
            ['Periode analisa (hari)', $p['days']],
            ['Periode', $p['start']->toDateString().' s.d. '.$p['end']->toDateString()],
            ['Target stok aman (hari jualan)', $p['targetDays']],
            ['Lama produksi / lead time (hari)', $p['leadDays']],
            ['Saran produksi dibulatkan ke kelipatan', $p['batch']],
            ['Stok lambat laku jika tidak terjual (hari)', $p['deadDays']],
            ['Filter', $filterText],
            [],
            ['', 'PERIODE INI', 'PERIODE SEBELUMNYA'],
            ['Terjual (pcs) dalam periode', $d['kpi']['sold'], $d['previous']['sold']],
            ['Omzet konveksi (tagihan) periode', $d['kpi']['bill'], $d['previous']['bill']],
            ['Laba kotor konveksi', $d['kpi']['bill'] - $d['kpi']['hpp']],
            ['SKU laku tapi stok HABIS', $d['kpi']['out_of_stock']],
            ['SKU harus SEGERA diproduksi', $d['kpi']['urgent']],
            ['Sedang diproduksi (SPK berjalan, pcs)', $d['kpi']['in_production']],
            ['Total saran produksi (pcs)', $d['kpi']['suggested']],
            [],
            ['Total retur (pcs)', $r['qty'], $d['previous']['returned']],
            ['Tingkat retur', $r['rate'], $d['previous']['rate']],
            ['Retur cacat produksi (pcs)', $r['production_defects']],
            ['Nilai retur', $r['value']],
            ['Retur kembali ke stok (pcs)', $r['restocked']],
        ] as $line) {
            $writer->addRow(new Row(array_map(fn ($value, int $i) => SpreadsheetCells::cell($value, match (true) {
                $i >= 1 && $line[0] === 'Tingkat retur' => $percent,
                $i >= 1 && is_numeric($value) => $money,
                default => null,
            }), $line, array_keys($line))));
        }

        $this->sheet($writer, 'A. Model Terlaris', [
            'NO' => 6, 'MODEL' => 34, 'KATEGORI' => 16, 'TERJUAL (pcs)' => 13, 'OMZET' => 15, 'STOK SEKARANG' => 14, 'HARI STOK' => 10, 'SARAN PRODUKSI' => 15,
        ], $d['top_models'], fn (array $m, int $i): array => [
            $i + 1, $m['model'], $m['category'], [$m['sold'], $money], [$m['bill'], $money], [$m['stock'], $money], $daysOf($m['days_of_stock']), [$m['suggested'], $money],
        ]);

        $this->sheet($writer, 'B. Varian Terlaris', [
            'NO' => 6, 'SKU' => 16, 'NAMA PRODUK' => 40, 'MODEL' => 30, 'TERJUAL (pcs)' => 13, 'STOK' => 10, 'HARI STOK' => 10, 'PRIORITAS' => 12,
        ], $d['top_variants'], fn (array $v, int $i): array => [
            $i + 1, $v['sku'], $v['name'], $v['model'], [$v['sold'], $money], [$v['stock'], $money], $daysOf($v['days_of_stock']), $v['priority'],
        ]);

        $this->sheet($writer, 'C. Prioritas Produksi', [
            'NO' => 6, 'PRIORITAS' => 12, 'SKU' => 16, 'NAMA PRODUK' => 40, 'MODEL' => 30, 'UKURAN' => 9, 'STOK' => 10, 'TERJUAL/HARI' => 13, 'HARI STOK' => 10, 'SARAN PRODUKSI (pcs)' => 18,
        ], $d['priority'], fn (array $v, int $i): array => [
            $i + 1, $v['priority'], $v['sku'], $v['name'], $v['model'], $v['size'] ?? 'ALL', [$v['stock'], $money], [$v['per_day'], $decimal], $daysOf($v['days_of_stock']), [$v['suggested'], $money],
        ]);

        $this->sheet($writer, 'D. Kebutuhan Bahan', [
            'NO' => 6, 'BAHAN' => 30, 'DIPAKAI SKU' => 12, 'KEBUTUHAN' => 13, 'STOK BAHAN' => 13, 'KEKURANGAN' => 13, 'SATUAN' => 8, 'PERLU BELI' => 11, 'SATUAN BELI' => 11, 'STATUS' => 10,
        ], $this->dashboard->materialNeeds($params)['rows'], fn (array $m, int $i): array => [
            $i + 1, $m['code'], $m['skus'], [$m['needed'], $decimal], [$m['stock'], $decimal], [$m['shortage'], $decimal], $m['uom'], $m['packs_to_buy'], $m['pack_uom'], $m['status'],
        ]);

        $this->sheet($writer, 'E. Margin per Model', [
            'NO' => 6, 'MODEL' => 34, 'KATEGORI' => 16, 'TERJUAL' => 10, 'OMZET (TAGIHAN)' => 16, 'HPP TERJUAL' => 15, 'LABA KOTOR' => 15, 'MARGIN' => 10, 'LABA / PCS' => 12,
        ], $d['margins'], fn (array $m, int $i): array => [
            $i + 1, $m['model'], $m['category'], [$m['sold'], $money], [$m['bill'], $money], [$m['hpp'], $money], [$m['profit'], $money], [$m['margin'], $percent], [$m['profit_per_pcs'], $money],
        ]);

        $this->sheet($writer, 'F. Stok Lambat Laku', [
            'NO' => 6, 'SKU' => 16, 'NAMA PRODUK' => 40, 'MODEL' => 30, 'STOK' => 10, 'HPP / PCS' => 12, 'MODAL TERTAHAN' => 16, 'TERAKHIR TERJUAL' => 16, 'TIDAK LAKU (HARI)' => 16,
        ], $d['dead_stock'], fn (array $v, int $i): array => [
            $i + 1, $v['sku'], $v['name'], $v['model'], [$v['stock'], $money], [$v['hpp_unit'], $money], [$v['stock_value'], $money], $v['last_sold'] ?? 'Belum pernah', $v['idle_days'],
        ]);

        $this->sheet($writer, 'G. Retur per SKU', [
            'NO' => 6, 'SKU' => 16, 'NAMA PRODUK' => 40, 'MODEL' => 30, 'TERJUAL' => 10, 'RETUR (pcs)' => 12, 'ALASAN TERBANYAK' => 24, '% RETUR' => 10, 'NILAI RETUR' => 14, 'MASUK STOK' => 12, 'CATATAN RETUR' => 40,
        ], $r['by_variant'], fn (array $v, int $i): array => [
            $i + 1, $v['sku'], $v['name'], $v['model'], [$v['sold_gross'], $money], [$v['returned'], $money], $v['top_reason'] ?? 'Belum diisi', [$v['return_rate'], $percent], [$v['returned_value'], $money], [$v['restocked'], $money], $v['notes'],
        ]);

        $this->sheet($writer, 'G. Retur per Alasan', [
            'NO' => 6, 'ALASAN' => 30, 'RETUR (pcs)' => 12, 'PORSI' => 10, 'SKU' => 8, 'NILAI RETUR' => 14,
        ], $r['by_reason'], fn (array $x, int $i): array => [
            $i + 1, $x['reason'], [$x['returned'], $money], [$x['share'], $percent], $x['sku_count'], [$x['returned_value'], $money],
        ]);

        $this->sheet($writer, 'G. Retur per Model', [
            'NO' => 6, 'MODEL' => 34, 'TERJUAL' => 10, 'RETUR' => 10, '% RETUR' => 10, 'NILAI RETUR' => 14,
        ], $r['by_model'], fn (array $m, int $i): array => [
            $i + 1, $m['model'], [$m['sold'], $money], [$m['returned'], $money], [$m['rate'], $percent], [$m['value'], $money],
        ]);

        $this->sheet($writer, 'G. Retur per Toko', [
            'NO' => 6, 'TOKO' => 30, 'TERJUAL' => 10, 'RETUR' => 10, '% RETUR' => 10, 'NILAI RETUR' => 14,
        ], $r['by_store'], fn (array $s, int $i): array => [
            $i + 1, $s['store'], [$s['sold'], $money], [$s['returned'], $money], [$s['rate'], $percent], [$s['returned_value'], $money],
        ]);

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, float>  $columns  header => lebar
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>, int): list<mixed>  $map  nilai, atau [nilai, Style]
     */
    private function sheet(AbstractWriterMultiSheets $writer, string $name, array $columns, Collection $rows, callable $map): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(Spreadsheet::sheetName($name));

        foreach (array_values($columns) as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Spreadsheet::headerRow(array_fill_keys(array_keys($columns), false)));

        foreach ($rows->values() as $index => $row) {
            $writer->addRow(new Row(array_map(
                fn ($value) => is_array($value) ? SpreadsheetCells::cell($value[0], $value[1]) : SpreadsheetCells::cell($value),
                $map($row, $index),
            )));
        }

        if ($writer instanceof XlsxWriter && $rows->isNotEmpty()) {
            $sheet->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, $rows->count() + 1));
        }
    }
}
