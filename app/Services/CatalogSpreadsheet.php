<?php

namespace App\Services;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Color;
use App\Models\LaborType;
use App\Models\Material;
use App\Models\MaterialPurchaseItem;
use App\Models\MaterialType;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Warehouse;
use App\Support\Spreadsheet;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Template & export workbook katalog (Bahan, Produk, Resep BOM, Pembelian Bahan).
 * Layout export = layout impor, jadi hasil export bisa diedit lalu diimpor ulang.
 */
class CatalogSpreadsheet
{
    /** Format multi-sheet. CSV hanya 1 sheet per file. */
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'ods' => 'OpenDocument (.ods)'];

    /** Kolom hasil hitung otomatis (header hijau): hanya di export, diabaikan saat impor. */
    private const AUTO_COLUMNS = [
        'products' => ['HPP BAHAN', 'HPP ONGKOS', 'HPP', 'MARGIN BERLAKU %', 'HARGA RESELLER', 'HARGA BERLAKU MULAI'],
    ];

    private const WIDTHS = [
        'materials' => [16, 20, 14, 11, 14, 22, 12, 11, 16, 10, 8, 11, 20, 12, 13],
        'products' => [16, 12, 24, 13, 13, 11, 14, 9, 16, 32, 9, 14, 8, 26, 11, 11, 11, 14, 14, 18],
        'boms' => [12, 9, 13, 24, 13, 16, 22, 15, 12, 16, 14],
        'purchases' => [12, 22, 15, 14, 12, 22, 10, 15, 14, 24],
    ];

    public function template(): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $this->writeDataSheets($writer, fn (): array => []);

        Spreadsheet::addSheet($writer, 'Petunjuk', [18, 26, 16, 90], [
            ['SHEET', 'KOLOM', 'WAJIB', 'KETERANGAN'],
            ['Umum', '', '', 'Nama sheet bebas; jenis sheet dikenali dari header. Sheet boleh dihapus bila tidak dipakai. Bisa juga 1 file CSV per sheet.'],
            ['', '', '', 'Urutan proses: Bahan > Produk > Resep BOM > Pembelian Bahan. Data yang sudah ada diperbarui dari sel yang terisi; sel kosong = tidak diubah.'],
            ['', '', '', 'Baris/dokumen yang valid tetap disimpan; yang error dilaporkan setelah impor.'],
            ['', '', '', 'Data bantu yang belum ada dibuat otomatis dari isi file: kategori, warna, ukuran, gudang, jenis bahan, bahan, model, jenis ongkos, supplier.'],
            ['', '', '', 'Bila kolomnya kosong dipakai default: kategori bahan ditebak dari satuan (pcs/gr = aksesoris, lainnya kain); model: nama dari NAMA VARIAN / kode, kategori "Umum";'],
            ['', '', '', 'kode warna dari nama warna; bahan dari kode "JENIS - WARNA" dengan satuan cm (kain) / pcs (aksesoris) dan isi 1. Semua tebakan ditampilkan di hasil impor untuk dicek.'],
            ['', '', '', ''],
            ['Bahan', 'KODE JENIS BAHAN', 'Ya', 'Mis. DRILL. KATEGORI BAHAN kain / aksesoris (kosong = ditebak dari satuan); NAMA JENIS BAHAN default = kode.'],
            ['Bahan', 'KODE / NAMA WARNA', '-', 'Warna bahan. Kode kosong = dibuat dari nama warna.'],
            ['Bahan', 'KODE BAHAN', '-', 'Kosong = otomatis "JENIS - WARNA". Bahan dicocokkan lewat kode, atau jenis + warna.'],
            ['Bahan', 'SATUAN DASAR', '-', 'cm / m / pcs / gr. Kosong untuk bahan baru = cm (kain) / pcs (aksesoris).'],
            ['Bahan', 'SATUAN BELI, ISI PER SATUAN BELI', '-', 'Mis. roll, 3000 (1 roll = 3000 cm). Kosong untuk bahan baru = isi 1 (cek!).'],
            ['Bahan', 'STOK AWAL, HARGA PER SATUAN DASAR', '-', 'Saldo awal (satuan dasar). Hanya sekali per bahan per gudang; impor ulang dilewati.'],
            ['Bahan', 'GUDANG, TGL STOK AWAL', '-', 'Kode/nama gudang (default gudang utama) dan tanggal (default hari ini).'],
            ['', '', '', ''],
            ['Produk', 'KATEGORI, KODE MODEL, NAMA MODEL', 'Kode: Ya', 'NAMA MODEL kosong = dari NAMA VARIAN tanpa warna/ukuran; KATEGORI kosong = Umum. JENIS PRODUK: produksi / beli_jadi / jasa.'],
            ['Produk', 'MARGIN MODEL % / MARGIN VARIAN %', '-', 'Mis. 30 atau 30%. Kosong = ikut default.'],
            ['Produk', 'KODE / NAMA WARNA, UKURAN', '-', 'Warna & ukuran varian.'],
            ['Produk', 'SKU VARIAN', 'Ya', 'Kunci varian. NAMA VARIAN kosong = "Model Warna Ukuran".'],
            ['Produk', 'SKU BIG SELLER', '-', 'Boleh lebih dari satu, pisahkan dengan koma. Hanya ditambahkan, tidak dihapus.'],
            ['Produk', 'HPP & HARGA RESELLER', 'Otomatis', 'Tidak diisi: HPP dihitung dari resep x harga beli bahan, harga dasar = HPP x (1 + margin), berlaku mulai tanggal resep/pembelian.'],
            ['', '', '', 'Di file export kolom ini berheader hijau (hanya info) dan diabaikan saat impor ulang.'],
            ['', '', '', ''],
            ['Resep BOM', 'KODE MODEL, UKURAN, BERLAKU MULAI', 'Model & tgl: Ya', '1 baris = 1 komponen. Baris dengan model + ukuran + tanggal sama = 1 resep. UKURAN kosong = semua ukuran.'],
            ['Resep BOM', 'JENIS BARIS', 'Ya', 'bahan / ongkos. Resep yang sudah ada diganti seluruh komponennya.'],
            ['Resep BOM', 'KODE JENIS BAHAN, KODE BAHAN', 'Jenis: baris bahan', 'KODE BAHAN kosong = warna ikut produk; diisi = bahan tetap (harus jenis yang sama). Jenis/bahan yang belum ada dibuat otomatis.'],
            ['Resep BOM', 'PEMAKAIAN PER PCS', '-', 'cm / pcs per produk (default 1). Untuk ongkos = pengali.'],
            ['Resep BOM', 'KODE ONGKOS, NAMA ONGKOS, ONGKOS PER PCS', 'Kode & ongkos: baris ongkos', 'Mis. JAHIT, Jahit, 5000.'],
            ['', '', '', ''],
            ['Pembelian Bahan', 'TANGGAL, SUPPLIER, NO REF, GUDANG', 'Tgl: Ya', '1 baris = 1 item. Baris dengan tanggal + supplier + no ref + gudang sama = 1 nota. Nota yang sama persis dilewati.'],
            ['Pembelian Bahan', 'KODE BAHAN, QTY BELI, TOTAL HARGA', 'Ya', 'QTY SATUAN DASAR kosong = QTY BELI x isi per satuan beli. Bahan belum ada = dibuat dari kode "JENIS - WARNA". Stok bertambah.'],
        ]);

        Spreadsheet::addSheet($writer, 'Daftar Gudang', [14, 30], [['KODE', 'NAMA'], ...Warehouse::query()->orderBy('code')->get()->map(fn (Warehouse $w): array => [$w->code, $w->name])->all()]);
        Spreadsheet::addSheet($writer, 'Daftar Warna', [12, 24], [['KODE WARNA', 'NAMA WARNA'], ...Color::query()->orderBy('code')->get()->map(fn (Color $c): array => [$c->code, $c->name])->all()]);
        Spreadsheet::addSheet($writer, 'Daftar Ukuran', [12], [['UKURAN'], ...Size::query()->orderBy('sort_order')->get()->map(fn (Size $s): array => [$s->code])->all()]);
        Spreadsheet::addSheet($writer, 'Daftar Kategori', [30], [['KATEGORI'], ...ProductCategory::query()->orderBy('name')->get()->map(fn (ProductCategory $c): array => [$c->name])->all()]);
        Spreadsheet::addSheet($writer, 'Daftar Jenis Bahan', [16, 24, 14], [['KODE JENIS BAHAN', 'NAMA', 'KATEGORI'], ...MaterialType::query()->orderBy('code')->get()->map(fn (MaterialType $t): array => [$t->code, $t->name, $t->kind->value])->all()]);
        Spreadsheet::addSheet($writer, 'Daftar Jenis Ongkos', [14, 24], [['KODE ONGKOS', 'NAMA'], ...LaborType::query()->orderBy('code')->get()->map(fn (LaborType $t): array => [$t->code, $t->name])->all()]);

        $writer->close();

        return $path;
    }

    /**
     * @param  string  $format  xlsx / ods (semua sheet) atau csv (+ $sheet)
     */
    public function export(string $format, ?string $sheet = null): string
    {
        $path = Spreadsheet::tempPath($format);

        if ($format === 'csv') {
            $writer = new CsvWriter;
            $writer->openToFile($path);
            $writer->addRow($this->headerRow($sheet, withAuto: true));
            $this->rows($sheet)->each(fn (array $row) => $writer->addRow(Row::fromValues($row)));
            $writer->close();

            return $path;
        }

        $writer = $format === 'ods' ? new OdsWriter : new XlsxWriter;
        $writer->openToFile($path);
        $this->writeDataSheets($writer, fn (string $type): Collection => $this->rows($type), withAuto: true);
        $writer->close();

        return $path;
    }

    /**
     * Header merah (diimpor) + header hijau kolom otomatis (hanya export).
     */
    private function headerRow(string $type, bool $withAuto): Row
    {
        return Spreadsheet::headerRow([
            ...array_fill_keys(array_keys(CatalogImporter::SHEETS[$type]['headers']), true),
            ...($withAuto ? array_fill_keys(self::AUTO_COLUMNS[$type] ?? [], false) : []),
        ]);
    }

    /**
     * @param  callable(string): iterable<list<mixed>>  $rows
     */
    private function writeDataSheets(AbstractWriterMultiSheets $writer, callable $rows, bool $withAuto = false): void
    {
        $first = true;

        foreach (CatalogImporter::SHEETS as $type => $definition) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($definition['title']);

            foreach (self::WIDTHS[$type] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow($this->headerRow($type, $withAuto));

            foreach ($rows($type) as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
    }

    /**
     * Baris export dengan urutan kolom = CatalogImporter::SHEETS.
     *
     * @return Collection<int, list<mixed>>
     */
    private function rows(string $type): Collection
    {
        $yesNo = fn (bool $value): string => $value ? 'Ya' : 'Tidak';
        $percent = fn ($value): ?string => $value === null ? null : rtrim(rtrim(number_format((float) $value * 100, 2, '.', ''), '0'), '.').'%';

        return match ($type) {
            'materials' => Material::query()->with(['materialType', 'color'])->orderBy('code')->get()
                ->map(fn (Material $m): array => [
                    $m->materialType->code, $m->materialType->name, $m->materialType->kind->value,
                    $m->color?->code, $m->color?->name, $m->code, $m->base_uom->value, $m->pack_uom,
                    (float) $m->qty_per_pack, (float) $m->min_stock, $yesNo($m->is_active), null, null, null, null,
                ]),
            'products' => ProductVariant::query()
                ->with(['model.category', 'color', 'size', 'skuAliases'])
                ->with(['costs' => fn ($q) => $q->orderByDesc('effective_from'), 'priceHistories' => fn ($q) => $q->orderByDesc('effective_from')])
                ->orderBy('sku')->get()
                ->map(function (ProductVariant $v) use ($yesNo, $percent): array {
                    // HPP & harga yang berlaku hari ini (otomatis dari resep + margin).
                    $current = fn ($items) => $items->first(fn ($item): bool => $item->effective_from->lte(today())) ?? $items->last();
                    $cost = $current($v->costs);
                    $price = $current($v->priceHistories);

                    return [
                        $v->model->category?->name, $v->model->code, $v->model->name, $v->model->kind->value, $percent($v->model->default_margin_pct),
                        $v->color?->code, $v->color?->name, $v->size?->code, $v->sku, $v->name, $v->min_stock, $percent($v->margin_pct),
                        $yesNo($v->is_active), $v->skuAliases->pluck('alias')->implode(', '),
                        $cost ? (float) $cost->material_cost : null, $cost ? (float) $cost->labor_cost : null, $cost ? (float) $cost->total_cost : null,
                        $percent($price?->margin_pct), $price ? (float) $price->base_price : null, $price?->effective_from->toDateString(),
                    ];
                }),
            'boms' => Bom::query()->with(['model', 'size', 'items.materialType', 'items.material', 'items.laborType'])
                ->orderBy('model_id')->orderBy('size_id')->orderBy('effective_from')->get()
                ->flatMap(fn (Bom $bom): Collection => $bom->items->map(fn (BomItem $item): array => [
                    $bom->model->code, $bom->size?->code, $bom->effective_from->toDateString(), $bom->note,
                    $item->line_type->getLabel(), $item->materialType?->code, $item->material?->code, (float) $item->qty_per_unit,
                    $item->laborType?->code, $item->laborType?->name, $item->labor_rate === null ? null : (float) $item->labor_rate,
                ])),
            'purchases' => MaterialPurchaseItem::query()->with(['purchase.supplier', 'purchase.warehouse', 'material'])
                ->join('material_purchases', 'material_purchases.id', '=', 'material_purchase_items.purchase_id')
                ->orderBy('material_purchases.purchase_date')->orderBy('material_purchases.id')->orderBy('material_purchase_items.id')
                ->select('material_purchase_items.*')->get()
                ->map(fn (MaterialPurchaseItem $item): array => [
                    $item->purchase->purchase_date->toDateString(), $item->purchase->supplier?->name, $item->purchase->supplier?->phone,
                    $item->purchase->ref_no, $item->purchase->warehouse->code, $item->material->code,
                    (float) $item->qty_pack, (float) $item->qty_base, (float) $item->total_price, $item->purchase->note,
                ]),
        };
    }
}
