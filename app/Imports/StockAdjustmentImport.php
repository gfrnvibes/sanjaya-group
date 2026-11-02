<?php

namespace App\Imports;

use App\Enums\StockAdjustmentReason;
use App\Models\StockAdjustment;
use App\Services\InventoryService;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Penyesuaian stok barang jadi (+ tambah / - kurang). Baris dengan tanggal + gudang + alasan + catatan sama = 1 dokumen.
 * Dokumen dengan isi sama persis yang sudah ada dilewati agar stok tidak dobel.
 */
class StockAdjustmentImport extends RowImporter
{
    public function __construct(private InventoryService $inventory) {}

    public function title(): string
    {
        return 'Penyesuaian Stok';
    }

    public function columns(): array
    {
        return [
            'TANGGAL' => ['adjusted_at', 'Ya', 'Tanggal penyesuaian.'],
            'GUDANG' => ['warehouse', '-', 'Kode/nama gudang. Kosong = gudang utama; belum ada = dibuat otomatis.'],
            'ALASAN' => ['reason', '-', 'Rusak / Sampel / Custom / Koreksi / Lainnya. Default Koreksi.'],
            'CATATAN' => ['note', '-', 'Baris dengan tanggal, gudang, alasan & catatan sama = 1 dokumen.'],
            'SKU VARIAN' => ['sku', 'Ya', 'SKU varian atau SKU BigSeller.'],
            'QTY' => ['qty', 'Ya', 'Bilangan bulat; positif = tambah stok, negatif = kurangi stok. Kosong / 0 = tidak disesuaikan (baris dilewati).'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['adjusted_at', 'sku', 'qty'];
    }

    protected function groupKey(SpreadsheetRow $row): ?string
    {
        return implode('|', [
            Spreadsheet::parseDate($row->text('adjusted_at')) ?? $row->text('adjusted_at'),
            Str::lower($row->text('warehouse')), Str::lower($row->text('reason')), Str::lower($row->text('note')),
        ]);
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $first */
        $first = $rows->first();
        $date = $first->date('adjusted_at', 'TANGGAL', required: true);
        $reason = $first->enum('reason', StockAdjustmentReason::class, 'ALASAN') ?? StockAdjustmentReason::Koreksi;
        $first->throwIfInvalid();

        // QTY kosong / 0 = SKU tidak disesuaikan, dilewati (file export berisi semua SKU).
        $items = collect($this->eachLine($rows, function (SpreadsheetRow $row): ?array {
            $qty = $row->number('qty', 'QTY', integer: true);
            $row->throwIfInvalid();

            if ((int) $qty === 0) {
                return null;
            }

            $row->requireText(['sku' => 'SKU VARIAN']);
            $row->throwIfInvalid();

            return ['variant_id' => $this->variant($row->text('sku'))->id, 'qty' => (int) $qty];
        }))
            ->filter()
            ->groupBy('variant_id')
            ->map(fn (Collection $lines): array => ['variant_id' => $lines->first()['variant_id'], 'qty' => $lines->sum('qty')])
            ->filter(fn (array $line): bool => $line['qty'] !== 0)
            ->values()
            ->all();

        if ($items === []) {
            $this->bump('skipped');

            return;
        }

        $warehouse = $this->warehouse($first->text('warehouse'));

        if ($this->sameDocumentExists($date, $warehouse->id, $reason, $items)) {
            $this->bump('skipped');

            return;
        }

        $this->inventory->createStockAdjustment([
            'warehouse_id' => $warehouse->id,
            'adjusted_at' => $date,
            'reason' => $reason,
            'note' => $first->text('note') ?: self::NOTE,
        ], $items);
        $this->bump('created');
    }

    /**
     * @param  list<array{variant_id: int, qty: int}>  $items
     */
    private function sameDocumentExists(string $date, int $warehouseId, StockAdjustmentReason $reason, array $items): bool
    {
        $signature = fn (iterable $lines): string => collect($lines)->map(fn ($line): string => $line['variant_id'].':'.$line['qty'])->sort()->implode('|');
        $expected = $signature($items);

        return StockAdjustment::query()
            ->with('items')
            ->whereDate('adjusted_at', $date)
            ->where('warehouse_id', $warehouseId)
            ->where('reason', $reason)
            ->get()
            ->contains(fn (StockAdjustment $adjustment): bool => $signature($adjustment->items->map(fn ($item): array => [
                'variant_id' => $item->variant_id, 'qty' => $item->qty,
            ])) === $expected);
    }
}
