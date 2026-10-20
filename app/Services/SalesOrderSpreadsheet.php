<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Store;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Template impor & export pesanan dengan layout sheet "INPUT ORDERAN RESELLER".
 * Kolom merah = diisi manual (diimpor), kolom hijau = dihitung otomatis.
 */
class SalesOrderSpreadsheet
{
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'ods' => 'OpenDocument (.ods)'];

    /** Kolom input (header merah) â€” urutan template impor. */
    public const INPUT_COLUMNS = ['TANGGAL', 'NAMA TOKO', 'SKU VARIAN', 'SKU BIG SELLER', 'QTY', 'PENDAPATAN TOKO'];

    /** Kolom export: true = input (merah), false = otomatis (hijau). */
    private const EXPORT_COLUMNS = [
        'TANGGAL' => true,
        'PEMILIK TOKO' => false,
        'NAMA TOKO' => true,
        'SKU VARIAN' => true,
        'SKU BIG SELLER' => true,
        'NAMA PRODUK' => false,
        'HARGA RESELLER' => false,
        'QTY' => true,
        'PENDAPATAN TOKO' => true,
        'MARGIN' => false,
        'TOTAL TAGIHAN' => false,
        'STATUS' => false,
        'NO. PESANAN' => false,
    ];

    /**
     * File template kosong (+ sheet petunjuk dan daftar referensi). Mengembalikan path file sementara.
     */
    public function template(): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Input Orderan');
        $sheet->setColumnWidth(14, 1);
        $sheet->setColumnWidth(30, 2);
        $sheet->setColumnWidth(16, 3);
        $sheet->setColumnWidth(24, 4);
        $sheet->setColumnWidth(8, 5);
        $sheet->setColumnWidth(18, 6);
        $writer->addRow(Spreadsheet::headerRow(array_fill_keys(self::INPUT_COLUMNS, true)));

        Spreadsheet::addSheet($writer, 'Petunjuk', [40, 90], [
            ['KOLOM', 'KETERANGAN'],
            ['TANGGAL', 'Tanggal pesanan. Format tanggal Excel atau teks YYYY-MM-DD / DD/MM/YYYY.'],
            ['NAMA TOKO', 'Harus sama persis dengan nama toko di sistem (lihat sheet Daftar Toko).'],
            ['SKU VARIAN', 'SKU varian di sistem (lihat sheet Daftar SKU). Boleh dikosongkan bila SKU BIG SELLER diisi.'],
            ['SKU BIG SELLER', 'SKU dari BigSeller/marketplace. Dipetakan otomatis lewat menu Pemetaan SKU.'],
            ['QTY', 'Jumlah barang, bilangan bulat minimal 1.'],
            ['PENDAPATAN TOKO', 'Pendapatan toko untuk baris ini (boleh kosong).'],
            ['', ''],
            ['Otomatis', 'Pemilik toko, nama produk, harga reseller, margin, total tagihan dihitung sistem sesuai tanggal pesanan.'],
            ['Catatan', 'Setiap baris menjadi 1 pesanan. Stok berkurang dari gudang yang dipilih saat impor.'],
            ['', 'Baris valid disimpan, baris error dilaporkan. Baris yang sama persis (tanggal, toko, SKU, qty, pendapatan) dengan pesanan yang sudah ada dilewati, jadi file yang sama aman diimpor ulang.'],
            ['Contoh', '2026-10-01 | Ground Supply Tiktok | TJN | | 1 | 52492'],
        ]);

        $this->referenceSheets($writer);
        $writer->close();

        return $path;
    }

    /**
     * File BARIS GAGAL: kolom input asli (merah, perbaiki di sini) + alasan gagal & cara memperbaiki (hijau).
     * File ini bisa langsung diimport ulang; kolom ID GAGAL menandai baris lama sebagai sudah diperbaiki.
     *
     * @param  Builder<SalesImportFailure>  $failures
     */
    public function failures(Builder $failures): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Baris Gagal');
        foreach ([14, 30, 16, 24, 8, 18, 10, 24, 60, 70, 30, 8] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Spreadsheet::headerRow([
            ...array_fill_keys(array_keys(SalesOrderImporter::COLUMNS), true),
            SalesOrderImporter::FAILURE_ID_COLUMN => false,
            'KATEGORI' => false,
            'ALASAN GAGAL' => false,
            'CARA MEMPERBAIKI' => false,
            'FILE ASAL' => false,
            'BARIS ASAL' => false,
        ]));

        $rowCount = 0;

        $failures->clone()
            ->where('line', '>', 0) // gagal per file (header salah) tidak punya baris data
            ->with('batch')
            ->reorder()
            ->orderBy('batch_id')
            ->orderBy('line')
            ->chunk(500, function ($chunk) use ($writer, &$rowCount): void {
                foreach ($chunk as $failure) {
                    /** @var SalesImportFailure $failure */
                    $writer->addRow(Row::fromValues([
                        ...array_map(fn (string $label): ?string => $failure->value($label), array_keys(SalesOrderImporter::COLUMNS)),
                        $failure->id,
                        $failure->reason->getLabel(),
                        $failure->message,
                        $failure->reason->hint(),
                        $failure->batch?->file_name,
                        $failure->line,
                    ]));
                    $rowCount++;
                }
            });

        if ($rowCount > 0) {
            $sheet->setAutoFilter(new AutoFilter(0, 1, 11, $rowCount + 1));
        }

        Spreadsheet::addSheet($writer, 'Petunjuk', [24, 100], [
            ['LANGKAH', 'KETERANGAN'],
            ['1. Perbaiki', 'Ubah kolom header MERAH sesuai ALASAN GAGAL dan CARA MEMPERBAIKI. Bila masalahnya di master data (toko belum terdaftar, SKU belum dipetakan, harga belum terbentuk), perbaiki dulu di menunya lalu biarkan baris apa adanya.'],
            ['2. Import ulang', 'Import file ini lewat Pesanan > Import Excel. Kolom hijau boleh dibiarkan (diabaikan sistem), kecuali ID GAGAL jangan diubah.'],
            ['3. Hasil', 'Baris yang berhasil otomatis berstatus "Sudah diperbaiki" di menu Import Gagal. Baris yang gagal lagi muncul sebagai baris gagal baru dengan alasan terbaru.'],
            ['Hapus baris', 'Baris yang memang tidak perlu diimport (mis. pesanan uji/batal) hapus dari file, lalu tandai "Abaikan" di menu Import Gagal.'],
            ['Aman diulang', 'Pesanan yang sudah tersimpan tidak akan dobel bila file ini diimport lebih dari sekali.'],
        ]);

        $this->referenceSheets($writer);
        $writer->close();

        return $path;
    }

    /**
     * Sheet bantu untuk mengisi / memperbaiki: daftar toko aktif dan SKU aktif + SKU BigSeller-nya.
     */
    private function referenceSheets(XlsxWriter $writer): void
    {
        Spreadsheet::addSheet($writer, 'Daftar Toko', [30, 24, 20], [
            ['NAMA TOKO', 'PEMILIK TOKO', 'CHANNEL'],
            ...Store::query()
                ->where('is_active', true)
                ->with(['reseller.owner', 'channel'])
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store): array => [$store->name, $store->reseller?->owner?->name, $store->channel?->name])
                ->all(),
        ]);

        Spreadsheet::addSheet($writer, 'Daftar SKU', [18, 45, 40], [
            ['SKU VARIAN', 'NAMA PRODUK', 'SKU BIG SELLER'],
            ...ProductVariant::query()
                ->where('is_active', true)
                ->with('skuAliases')
                ->orderBy('sku')
                ->get()
                ->map(fn (ProductVariant $variant): array => [$variant->sku, $variant->name, $variant->skuAliases->pluck('alias')->implode(', ')])
                ->all(),
        ]);
    }

    /**
     * Export baris pesanan (1 baris per produk) dari query pesanan yang sudah difilter.
     *
     * @param  Builder<SalesOrder>  $orders
     */
    public function export(Builder $orders, string $format): string
    {
        $path = Spreadsheet::tempPath($format);
        $writer = $this->writer($format);
        $writer->openToFile($path);

        if ($writer instanceof AbstractWriterMultiSheets) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Orderan Reseller');
            foreach ([12, 16, 28, 14, 22, 40, 16, 7, 18, 16, 16, 10, 22] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }
        }

        $writer->addRow(Spreadsheet::headerRow(self::EXPORT_COLUMNS));

        $money = (new Style)->setFormat('#,##0');
        $rowCount = 0;

        SalesOrderItem::query()
            ->whereIn('sales_order_id', $orders->clone()->reorder()->select('sales_orders.id'))
            ->with(['salesOrder.store.reseller.owner', 'variant'])
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->orderBy('sales_orders.order_date')
            ->orderBy('sales_orders.id')
            ->orderBy('sales_order_items.id')
            ->select('sales_order_items.*')
            ->chunk(500, function ($items) use ($writer, $money, &$rowCount): void {
                foreach ($items as $item) {
                    /** @var SalesOrderItem $item */
                    $order = $item->salesOrder;
                    $writer->addRow(new Row([
                        Cell::fromValue($order->order_date->toDateString()),
                        Cell::fromValue($order->store?->reseller?->owner?->name),
                        Cell::fromValue($order->store?->name),
                        Cell::fromValue($item->variant?->sku),
                        Cell::fromValue($item->sku_raw !== $item->variant?->sku ? $item->sku_raw : null),
                        Cell::fromValue($item->variant?->name),
                        Cell::fromValue((float) $item->reseller_unit_price, $money),
                        Cell::fromValue($item->qty),
                        Cell::fromValue($item->revenue === null ? null : (float) $item->revenue, $money),
                        Cell::fromValue($item->margin === null ? null : (float) $item->margin, $money),
                        Cell::fromValue((float) $item->bill_amount, $money),
                        Cell::fromValue($order->status->getLabel()),
                        Cell::fromValue($order->order_no),
                    ]));
                    $rowCount++;
                }
            });

        if ($writer instanceof XlsxWriter && $rowCount > 0) {
            $writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, count(self::EXPORT_COLUMNS) - 1, $rowCount + 1));
        }

        $writer->close();

        return $path;
    }

    public function fileName(string $prefix, string $format): string
    {
        return $prefix.'-'.now()->format('Ymd-His').'.'.$format;
    }

    private function writer(string $format): WriterInterface
    {
        return match ($format) {
            'csv' => new CsvWriter,
            'ods' => new OdsWriter,
            default => new XlsxWriter,
        };
    }
}
