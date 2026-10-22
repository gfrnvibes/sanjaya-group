<?php

namespace App\Imports;

use App\Enums\GoodsReceiptSource;
use App\Models\GoodsReceipt;
use App\Services\InventoryService;
use App\Services\PricingService;
use App\Support\DocumentNumber;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Barang masuk (stok barang jadi bertambah). 1 baris = 1 SKU; baris dengan NO DOKUMEN sama
 * (atau tanggal + gudang + sumber + supplier sama bila NO DOKUMEN kosong) = 1 dokumen.
 * Dokumen yang sudah pernah diimpor (no. dokumen sama, atau isi sama persis) dilewati agar stok tidak dobel.
 */
class GoodsReceiptImport extends RowImporter
{
    public function __construct(private InventoryService $inventory, private PricingService $pricing) {}

    public function title(): string
    {
        return 'Barang Masuk';
    }

    public function columns(): array
    {
        return [
            'NO DOKUMEN' => ['receipt_no', '-', 'Kosong = dibuat otomatis (BM-yymmdd-nnn). Baris dengan no. sama = 1 dokumen.'],
            'TANGGAL' => ['received_at', 'Ya', 'Tanggal barang diterima.'],
            'GUDANG' => ['warehouse', '-', 'Kode/nama gudang tujuan. Kosong = gudang utama; belum ada = dibuat otomatis.'],
            'SUMBER' => ['source', '-', 'Produksi / Borongan / Beli Jadi / Retur / Lainnya. Default Lainnya.'],
            'SUPPLIER' => ['supplier', '-', 'Belum ada = dibuat otomatis.'],
            'SKU VARIAN' => ['sku', 'Ya', 'SKU varian atau SKU BigSeller.'],
            'QTY' => ['qty', 'Ya', 'Bilangan bulat > 0.'],
            'HARGA SATUAN' => ['unit_cost', 'Beli Jadi', 'Harga beli per pcs. Wajib untuk sumber Beli Jadi: HPP & harga reseller produk beli jadi dihitung otomatis dari harga ini + packing + margin.'],
            'CATATAN' => ['note', '-', 'Diambil dari baris pertama dokumen.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['received_at', 'sku', 'qty'];
    }

    protected function groupKey(SpreadsheetRow $row): ?string
    {
        if ($row->filled('receipt_no')) {
            return 'no|'.Str::lower($row->text('receipt_no'));
        }

        return implode('|', [
            Spreadsheet::parseDate($row->text('received_at')) ?? $row->text('received_at'),
            Str::lower($row->text('warehouse')), Str::lower($row->text('source')), Str::lower($row->text('supplier')),
        ]);
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $first */
        $first = $rows->first();
        $date = $first->date('received_at', 'TANGGAL', required: true);
        $source = $first->enum('source', GoodsReceiptSource::class, 'SUMBER') ?? GoodsReceiptSource::Lainnya;
        $first->maxLength(['receipt_no' => ['NO DOKUMEN', 40]]);
        $first->throwIfInvalid();

        $receiptNo = $first->text('receipt_no');

        if ($receiptNo !== '' && $this->findCi(GoodsReceipt::query(), 'receipt_no', $receiptNo)) {
            $this->bump('skipped');

            return;
        }

        $items = collect($this->eachLine($rows, function (SpreadsheetRow $row) use ($source): array {
            $row->requireText(['sku' => 'SKU VARIAN']);
            $qty = $row->number('qty', 'QTY', min: 1, required: true, integer: true);
            $cost = $row->number('unit_cost', 'HARGA SATUAN', min: 0, required: $source === GoodsReceiptSource::BeliJadi);
            $row->throwIfInvalid();

            return ['variant_id' => $this->variant($row->text('sku'))->id, 'qty' => (int) $qty, 'unit_cost' => $cost];
        }))
            // SKU sama dalam 1 dokumen digabung (1 SKU 1 baris per dokumen).
            ->groupBy('variant_id')
            ->map(fn (Collection $lines): array => [...$lines->first(), 'qty' => $lines->sum('qty')])
            ->values()
            ->all();

        $warehouse = $this->warehouse($first->text('warehouse'));
        $supplier = $first->filled('supplier') ? $this->supplier($first->text('supplier')) : null;

        if ($receiptNo === '' && $this->sameDocumentExists($date, $warehouse->id, $source, $supplier?->id, $items)) {
            $this->bump('skipped');

            return;
        }

        $this->inventory->createGoodsReceipt([
            'receipt_no' => $receiptNo !== '' ? $receiptNo : $this->nextNumber($date),
            'warehouse_id' => $warehouse->id,
            'received_at' => $date,
            'source' => $source,
            'supplier_id' => $supplier?->id,
            'note' => $first->text('note') ?: self::NOTE,
        ], $items);
        $this->bump('created');

        if ($source === GoodsReceiptSource::BeliJadi) {
            foreach ($this->pricing->syncForReadyMade(array_column($items, 'variant_id'), $date)['skipped'] as $skipped) {
                $this->warn("harga beli jadi {$skipped}");
            }
        }
    }

    /**
     * @param  list<array{variant_id: int, qty: int}>  $items
     */
    private function sameDocumentExists(string $date, int $warehouseId, GoodsReceiptSource $source, ?int $supplierId, array $items): bool
    {
        $signature = fn (iterable $lines): string => collect($lines)->map(fn ($line): string => $line['variant_id'].':'.$line['qty'])->sort()->implode('|');
        $expected = $signature($items);

        return GoodsReceipt::query()
            ->with('items')
            ->whereDate('received_at', $date)
            ->where('warehouse_id', $warehouseId)
            ->where('source', $source)
            ->where('supplier_id', $supplierId)
            ->get()
            ->contains(fn (GoodsReceipt $receipt): bool => $signature($receipt->items->map(fn ($item): array => [
                'variant_id' => $item->variant_id, 'qty' => $item->qty,
            ])) === $expected);
    }

    private function nextNumber(string $date): string
    {
        return DocumentNumber::next('BM-'.Carbon::parse($date)->format('ymd').'-', 'goods_receipts', 'receipt_no', 3);
    }
}
