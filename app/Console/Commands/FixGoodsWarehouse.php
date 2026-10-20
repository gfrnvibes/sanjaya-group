<?php

namespace App\Console\Commands;

use App\Enums\WarehouseKind;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * PERBAIKAN DATA: stok barang jadi yang tercatat di gudang berjenis BAHAN dipindahkan ke gudang barang jadi.
 *
 * Mutasi barang jadi (penjualan, retur, hasil SPK, barang masuk, penyesuaian, opname) di gudang bahan,
 * beserta dokumen sumbernya, diubah gudangnya ke gudang barang jadi; saldo stok dihitung ulang dari kartu stok.
 * Proteksi ledger (trigger stock_movements_ro) dibuka sementara di dalam 1 transaksi lalu dipasang lagi.
 * ID baris yang diubah disimpan ke storage/app/private/backups sebelum perubahan.
 */
#[Signature('stock:fix-goods-warehouse {--to= : Kode gudang barang jadi tujuan (default: gudang barang jadi default)} {--dry-run : Hanya tampilkan yang akan diubah} {--force : Jalankan tanpa konfirmasi}')]
#[Description('Pindahkan riwayat stok barang jadi yang salah tercatat di gudang bahan ke gudang barang jadi')]
class FixGoodsWarehouse extends Command
{
    /** Dokumen barang jadi yang menyimpan warehouse_id. */
    private const GOODS_DOCUMENTS = [
        'sales_orders' => 'Pesanan',
        'sales_returns' => 'Retur',
        'goods_receipts' => 'Barang Masuk',
        'stock_adjustments' => 'Penyesuaian Stok',
        'stock_opnames' => 'Stok Opname',
    ];

    public function handle(): int
    {
        $sources = Warehouse::query()->where('kind', WarehouseKind::Bahan)->get();
        $target = $this->option('to')
            ? Warehouse::query()->where('code', $this->option('to'))->first()
            : Warehouse::defaultForGoods();

        if ($target === null || ! $target->kind->storesGoods()) {
            $this->error('Gudang barang jadi tujuan tidak ditemukan. Set jenis gudang dulu atau gunakan --to=KODE.');

            return self::FAILURE;
        }

        $sourceIds = $sources->pluck('id')->all();

        if ($sourceIds === []) {
            $this->info('Tidak ada gudang berjenis Bahan Baku. Tidak ada yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $this->info('Gudang bahan: '.$sources->map(fn (Warehouse $w): string => "{$w->code} ({$w->name})")->implode(', '));
        $this->info("Tujuan     : {$target->code} ({$target->name})");

        $movements = DB::table('stock_movements')->whereIn('warehouse_id', $sourceIds)
            ->selectRaw('movement_type, COUNT(*) AS n, SUM(qty) AS qty, COUNT(DISTINCT variant_id) AS sku')
            ->groupBy('movement_type')->orderBy('movement_type')->get();

        $this->table(['Jenis mutasi', 'Baris', 'Qty', 'SKU'], $movements->map(fn ($m): array => [$m->movement_type, $m->n, $m->qty, $m->sku])->all());

        $documents = collect(self::GOODS_DOCUMENTS)
            ->map(fn (string $label, string $table): array => [$label, DB::table($table)->whereIn('warehouse_id', $sourceIds)->count()]);
        $workOrders = DB::table('work_orders')->whereIn('warehouse_id', $sourceIds)
            ->where(fn ($q) => $q->whereNull('goods_warehouse_id')->orWhereIn('goods_warehouse_id', $sourceIds))->count();
        $this->table(['Dokumen', 'Jumlah'], [...$documents->values()->all(), ['SPK (gudang hasil)', $workOrders]]);

        $misplacedMaterials = DB::table('material_movements')
            ->join('warehouses', 'warehouses.id', '=', 'material_movements.warehouse_id')
            ->where('warehouses.kind', WarehouseKind::BarangJadi->value)->count();

        if ($misplacedMaterials > 0) {
            $this->warn("{$misplacedMaterials} mutasi BAHAN tercatat di gudang barang jadi; tidak diubah oleh command ini, periksa manual.");
        }

        if ($movements->isEmpty() && $documents->sum(1) === 0 && $workOrders === 0) {
            $this->info('Tidak ada stok barang jadi di gudang bahan.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run: tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Pindahkan semua data di atas ke '.$target->name.'?')) {
            return self::FAILURE;
        }

        $backup = 'backups/fix-goods-warehouse-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($backup, json_encode([
            'target_warehouse_id' => $target->id,
            'stock_movements' => DB::table('stock_movements')->whereIn('warehouse_id', $sourceIds)->pluck('warehouse_id', 'id'),
            ...collect(self::GOODS_DOCUMENTS)->mapWithKeys(fn ($label, string $table): array => [
                $table => DB::table($table)->whereIn('warehouse_id', $sourceIds)->pluck('warehouse_id', 'id'),
            ])->all(),
            'work_orders.goods_warehouse_id' => DB::table('work_orders')->whereIn('warehouse_id', $sourceIds)->pluck('goods_warehouse_id', 'id'),
        ]));
        $this->info("Backup ID baris lama: storage/app/private/{$backup}");

        try {
            DB::transaction(function () use ($sourceIds, $target): void {
                $affected = [...$sourceIds, $target->id];
                $in = implode(',', array_fill(0, count($sourceIds), '?'));

                DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_ro');

                DB::update("UPDATE stock_movements SET warehouse_id = ? WHERE warehouse_id IN ({$in})", [$target->id, ...$sourceIds]);

                foreach (array_keys(self::GOODS_DOCUMENTS) as $table) {
                    DB::update("UPDATE {$table} SET warehouse_id = ? WHERE warehouse_id IN ({$in})", [$target->id, ...$sourceIds]);
                }

                DB::update(
                    "UPDATE work_orders SET goods_warehouse_id = ? WHERE warehouse_id IN ({$in}) AND (goods_warehouse_id IS NULL OR goods_warehouse_id IN ({$in}))",
                    [$target->id, ...$sourceIds, ...$sourceIds],
                );

                // Saldo = jumlah kartu stok (hitung ulang untuk gudang yang terdampak).
                $all = implode(',', array_fill(0, count($affected), '?'));
                DB::delete("DELETE FROM stock_balances WHERE warehouse_id IN ({$all})", $affected);
                DB::insert("INSERT INTO stock_balances (variant_id, warehouse_id, qty, updated_at)
                            SELECT variant_id, warehouse_id, SUM(qty), now() FROM stock_movements
                            WHERE warehouse_id IN ({$all}) GROUP BY variant_id, warehouse_id", $affected);

                DB::statement('ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_ro');
            });
        } catch (Throwable $exception) {
            $this->error('Gagal, semua perubahan dibatalkan: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (blank(Setting::valueOf('default_warehouse_id'))) {
            Setting::updateOrCreate(['key' => 'default_warehouse_id'], ['value' => $target->id]);
            $this->info("Pengaturan default_warehouse_id diisi {$target->code}.");
        }

        if (blank(Setting::valueOf('default_material_warehouse_id')) && $sources->count() === 1) {
            Setting::updateOrCreate(['key' => 'default_material_warehouse_id'], ['value' => $sources->first()->id]);
            $this->info("Pengaturan default_material_warehouse_id diisi {$sources->first()->code}.");
        }

        $this->info('Selesai. Stok barang jadi di gudang bahan sekarang: '.DB::table('stock_balances')->whereIn('warehouse_id', $sourceIds)->count().' baris.');

        return self::SUCCESS;
    }
}
