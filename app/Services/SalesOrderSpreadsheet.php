<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
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

    /** Kolom input (header merah) — urutan template impor. */
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
        $path = $this->tempPath('xlsx');
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
        $writer->addRow($this->headerRow(array_fill_keys(self::INPUT_COLUMNS, true)));

        $this->addSheet($writer, 'Petunjuk', [40, 90], [
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
            ['Contoh', '2026-10-01 | Ground Supply Tiktok | TJN | | 1 | 52492'],
        ]);

        $this->addSheet($writer, 'Daftar Toko', [30, 24, 20], [
            ['NAMA TOKO', 'PEMILIK TOKO', 'CHANNEL'],
            ...Store::query()
                ->where('is_active', true)
                ->with(['reseller.owner', 'channel'])
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store): array => [$store->name, $store->reseller?->owner?->name, $store->channel?->name])
                ->all(),
        ]);

        $this->addSheet($writer, 'Daftar SKU', [18, 45, 40], [
            ['SKU VARIAN', 'NAMA PRODUK', 'SKU BIG SELLER'],
            ...ProductVariant::query()
                ->where('is_active', true)
                ->with('skuAliases')
                ->orderBy('sku')
                ->get()
                ->map(fn (ProductVariant $variant): array => [$variant->sku, $variant->name, $variant->skuAliases->pluck('alias')->implode(', ')])
                ->all(),
        ]);

        $writer->close();

        return $path;
    }

    /**
     * Export baris pesanan (1 baris per produk) dari query pesanan yang sudah difilter.
     *
     * @param  Builder<SalesOrder>  $orders
     */
    public function export(Builder $orders, string $format): string
    {
        $path = $this->tempPath($format);
        $writer = $this->writer($format);
        $writer->openToFile($path);

        if ($writer instanceof AbstractWriterMultiSheets) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Orderan Reseller');
            foreach ([12, 16, 28, 14, 22, 40, 16, 7, 18, 16, 16, 10, 22] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }
        }

        $writer->addRow($this->headerRow(self::EXPORT_COLUMNS));

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

    /**
     * @param  array<string, bool>  $columns  header => true bila kolom input
     */
    private function headerRow(array $columns): Row
    {
        $cells = [];

        foreach ($columns as $label => $isInput) {
            $cells[] = Cell::fromValue($label, (new Style)
                ->setFontBold()
                ->setCellAlignment(CellAlignment::CENTER)
                ->setFontColor($isInput ? Color::WHITE : Color::BLACK)
                ->setBackgroundColor($isInput ? Color::RED : 'C6EFCE'));
        }

        return new Row($cells);
    }

    /**
     * @param  list<float>  $widths
     * @param  list<list<mixed>>  $rows  baris pertama = header
     */
    private function addSheet(XlsxWriter $writer, string $name, array $widths, array $rows): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);

        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $header = array_shift($rows);
        $writer->addRow(Row::fromValues($header, (new Style)->setFontBold()->setBackgroundColor('D9D9D9')));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }

    private function tempPath(string $extension): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;
    }
}
