<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\StockLedger;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * STOK OPNAME BARANG JADI lewat Excel: export stok sistem -> isi STOK FISIK hasil hitung -> import.
 * Baris dengan gudang + tanggal opname sama = 1 dokumen Stok Opname (draft, atau langsung diposting).
 * Saat posting, selisih (fisik - stok sistem terkini) dicatat ke kartu stok sebagai mutasi opname.
 * Baris dengan STOK FISIK kosong diabaikan (tidak dihitung).
 */
class GoodsStockCountImport extends RowImporter
{
    /** Langsung posting opname (stok disesuaikan) atau simpan sebagai draft untuk diperiksa. */
    public bool $post = false;

    public function __construct(private InventoryService $inventory, private StockLedger $ledger) {}

    public function title(): string
    {
        return 'Opname Barang Jadi';
    }

    public function columns(): array
    {
        return [
            'KODE GUDANG' => ['warehouse', 'Ya', 'Kode gudang barang jadi (jangan diubah).'],
            'SKU VARIAN' => ['sku', 'Ya', 'SKU varian (jangan diubah).'],
            'NAMA PRODUK' => ['name', '-', 'Info dari sistem.'],
            'STOK SISTEM' => ['system_qty', '-', 'Stok menurut sistem saat file di-export (info).'],
            'STOK FISIK' => ['counted_qty', 'Untuk SKU yang dihitung', 'Hasil hitung fisik di rak (bilangan bulat >= 0). Kosong = SKU tidak dihitung, diabaikan.'],
            'TANGGAL OPNAME' => ['counted_at', '-', 'Waktu hitung fisik (YYYY-MM-DD HH:MM:SS, WIB). Kosong = saat impor.'],
            'CATATAN' => ['note', '-', 'Catatan dokumen opname (baris pertama per gudang + tanggal).'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['NAMA PRODUK', 'STOK SISTEM'];
    }

    protected function requiredHeaders(): array
    {
        return ['warehouse', 'sku', 'counted_qty'];
    }

    protected function notes(): array
    {
        return [
            'Alur: Export -> hitung fisik -> isi STOK FISIK -> Import. Selisih dicatat sebagai Stok Opname, stok tidak ditimpa langsung.',
            'Baris dengan gudang + tanggal opname sama menjadi 1 dokumen Stok Opname. Pilih "langsung posting" saat import, atau periksa dulu di menu Stok Opname lalu klik Posting.',
            'Selisih dihitung dari stok sistem PADA WAKTU HITUNG. Bila ada penjualan/barang masuk setelah file di-export, muncul peringatan.',
        ];
    }

    protected function groupKey(SpreadsheetRow $row): ?string
    {
        if (! $row->filled('counted_qty')) {
            return '#skip';
        }

        return Str::lower($row->text('warehouse')).'|'.(Spreadsheet::parseCountedAt($row->text('counted_at'))?->format('Y-m-d H:i:s') ?? '#current');
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $first */
        $first = $rows->first();

        if (! $first->filled('counted_qty')) {
            return; // STOK FISIK kosong: SKU tidak dihitung.
        }

        $countedAt = $first->countedAt('counted_at');
        $first->throwIfInvalid();

        $warehouse = $this->findWarehouse($first->text('warehouse'));

        $items = collect($this->eachLine($rows, function (SpreadsheetRow $row) use ($warehouse, $countedAt): array {
            $row->requireText(['sku' => 'SKU VARIAN']);
            $counted = $row->number('counted_qty', 'STOK FISIK', min: 0, required: true, integer: true);
            $exported = $row->number('system_qty', 'STOK SISTEM', integer: true);
            $row->throwIfInvalid();

            $variant = ProductVariant::query()->whereRaw('lower(sku) = ?', [Str::lower($row->text('sku'))])->first()
                ?? throw new BusinessRuleException("SKU {$row->text('sku')} tidak ditemukan");
            $current = $this->ledger->stockQtyAt($variant->id, $warehouse->id, $countedAt);

            if ($exported !== null && (int) $exported !== $current) {
                $this->warn("{$variant->sku}: stok sistem berubah sejak export ({$exported} -> {$current}); selisih dihitung pada waktu hitung fisik.");
            }

            return ['variant_id' => $variant->id, 'sku' => $variant->sku, 'system_qty' => $current, 'counted_qty' => (int) $counted];
        }));

        $duplicates = $items->duplicates('variant_id');

        if ($duplicates->isNotEmpty()) {
            throw new BusinessRuleException('SKU dobel dalam 1 opname: '.$items->whereIn('variant_id', $duplicates)->pluck('sku')->unique()->implode(', '));
        }

        $opname = StockOpname::create([
            'warehouse_id' => $warehouse->id,
            'counted_at' => $countedAt,
            'status' => 'draft',
            'note' => $first->text('note') ?: 'Opname dari Excel',
            'created_by' => auth()->id(),
        ]);

        foreach ($items as $item) {
            $opname->items()->create(['variant_id' => $item['variant_id'], 'system_qty' => $item['system_qty'], 'counted_qty' => $item['counted_qty']]);
        }

        $diff = $items->sum(fn (array $item): int => $item['counted_qty'] - $item['system_qty']);
        $changed = $items->filter(fn (array $item): bool => $item['counted_qty'] !== $item['system_qty'])->count();

        if ($this->post) {
            $this->inventory->postOpname($opname);
            $this->bump('created', 'Opname diposting');
        } else {
            $this->bump('created', 'Opname draft');
            $this->warn("opname {$warehouse->code} {$countedAt} disimpan sebagai DRAFT ({$changed} SKU selisih, total ".($diff >= 0 ? '+' : '')."{$diff} pcs) — periksa lalu posting di menu Stok Opname.");
        }

        foreach ($items as $item) {
            $this->bump($item['counted_qty'] === $item['system_qty'] ? 'skipped' : 'updated', 'SKU dihitung');
        }
    }

    protected function exportRows(): iterable
    {
        $warehouses = Warehouse::query()->forGoods()->where('is_active', true)->orderBy('code')->get();
        $balances = StockBalance::query()->whereIn('warehouse_id', $warehouses->pluck('id'))->get()
            ->keyBy(fn (StockBalance $b): string => $b->warehouse_id.'|'.$b->variant_id);
        $variants = ProductVariant::query()->orderBy('sku')->get(['id', 'sku', 'name', 'is_active']);

        foreach ($warehouses as $warehouse) {
            foreach ($variants as $variant) {
                $balance = $balances->get($warehouse->id.'|'.$variant->id);

                // Varian nonaktif tanpa saldo tidak perlu dihitung.
                if ($balance === null && ! $variant->is_active) {
                    continue;
                }

                yield [$warehouse->code, $variant->sku, $variant->name, (int) ($balance?->qty ?? 0), null, null, null];
            }
        }
    }

    private function findWarehouse(string $value): Warehouse
    {
        $warehouse = Warehouse::query()->whereRaw('lower(code) = ? or lower(name) = ?', [Str::lower($value), Str::lower($value)])->first()
            ?? throw new BusinessRuleException("gudang {$value} tidak ditemukan");

        if (! $warehouse->kind->storesGoods()) {
            throw new BusinessRuleException("\"{$warehouse->name}\" adalah gudang bahan baku; opname barang jadi harus di gudang barang jadi");
        }

        return $warehouse;
    }
}
