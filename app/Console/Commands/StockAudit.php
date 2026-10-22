<?php

namespace App\Console\Commands;

use App\Support\Spreadsheet;
use App\Support\SpreadsheetCells;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\XLSX\Writer;

class StockAudit extends Command
{
    protected $signature = 'stock:audit {--export : Buat workbook rekonsiliasi saldo negatif}';

    protected $description = 'Periksa saldo negatif dan kesesuaian saldo dengan ledger tanpa mengubah stok';

    public function handle(): int
    {
        $goods = DB::table('stock_balances as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('product_variants as v', 'v.id', '=', 'b.variant_id')->where('b.qty', '<', 0)->orderBy('w.code')->orderBy('v.sku')->get(['w.code', 'v.sku', 'v.name', 'b.qty']);
        $materials = DB::table('material_balances as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('materials as m', 'm.id', '=', 'b.material_id')->where('b.qty', '<', 0)->orderBy('w.code')->orderBy('m.code')->get(['w.code as warehouse', 'm.code', 'm.base_uom', 'b.qty']);
        $this->table(['Jenis', 'Saldo negatif', 'Total qty negatif'], [['Barang jadi', $goods->count(), $goods->sum('qty')], ['Bahan', $materials->count(), $materials->sum('qty')]]);
        $mismatch = 0;
        foreach (['stock' => 'variant_id', 'material' => 'material_id'] as $kind => $field) {
            $count = (int) DB::scalar("WITH m AS (SELECT {$field},warehouse_id,SUM(qty) AS qty FROM {$kind}_movements GROUP BY 1,2)
                SELECT COUNT(*) FROM m FULL JOIN {$kind}_balances b USING({$field},warehouse_id) WHERE COALESCE(m.qty,0)<>COALESCE(b.qty,0)");
            $mismatch += $count;
            $this->line("Selisih saldo/ledger {$kind}: {$count}");
        }
        if ($this->option('export')) {
            $path = Spreadsheet::tempPath('xlsx');
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Opname Barang Jadi');
            $writer->addRow(SpreadsheetCells::row(['KODE GUDANG', 'SKU VARIAN', 'NAMA PRODUK', 'STOK SISTEM', 'STOK FISIK', 'TANGGAL OPNAME', 'CATATAN']));
            foreach ($goods as $row) {
                $writer->addRow(SpreadsheetCells::row([$row->code, $row->sku, $row->name, (int) $row->qty, null, null, null]));
            }
            Spreadsheet::addSheet($writer, 'Opname Bahan', [20, 24, 12, 18, 18, 20, 40], [
                ['KODE GUDANG', 'KODE BAHAN', 'SATUAN', 'STOK SISTEM', 'STOK FISIK', 'TANGGAL OPNAME', 'CATATAN'],
                ...$materials->map(fn ($row): array => [$row->warehouse, $row->code, $row->base_uom, (float) $row->qty, null, null, null])->all(),
            ]);
            $writer->close();
            $this->info('Workbook: '.$path);
            $this->warn('Isi stok fisik dan waktu hitung sebenarnya, lalu impor sheet masing-masing lewat menu Opname. Saldo tidak dikoreksi otomatis.');
        }

        return $mismatch === 0 ? self::SUCCESS : self::FAILURE;
    }
}
