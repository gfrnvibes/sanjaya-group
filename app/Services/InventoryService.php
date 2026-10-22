<?php

namespace App\Services;

use App\Enums\MaterialMovementType;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Models\MaterialPurchase;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen stok: pembelian bahan, barang masuk, penyesuaian, stok opname, stok awal.
 * Setiap aksi = 1 transaksi database; dokumen + ledger tersimpan bersama.
 */
class InventoryService
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function createMaterialPurchase(array $data, array $items): MaterialPurchase
    {
        $this->ensureItems($items);

        return DB::transaction(function () use ($data, $items): MaterialPurchase {
            $purchase = MaterialPurchase::create([...$data, 'created_by' => auth()->id()]);

            foreach ($items as $row) {
                $item = $purchase->items()->create($row);
                $item->refresh();

                $this->ledger->moveMaterial(
                    $item->material_id, $purchase->warehouse_id, $item->qty_base, MaterialMovementType::PurchaseIn,
                    $item, $item->unit_cost, movedAt: $purchase->purchase_date,
                );
            }

            return $purchase;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function createGoodsReceipt(array $data, array $items): GoodsReceipt
    {
        $this->ensureItems($items);

        return DB::transaction(function () use ($data, $items): GoodsReceipt {
            $receipt = GoodsReceipt::create([...$data, 'created_by' => auth()->id()]);

            foreach ($items as $row) {
                $item = $receipt->items()->create($row);

                $this->ledger->moveStock(
                    $item->variant_id, $receipt->warehouse_id, $item->qty, StockMovementType::GoodsIn,
                    $item, $item->unit_cost, movedAt: $receipt->received_at,
                );
            }

            return $receipt;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function createStockAdjustment(array $data, array $items): StockAdjustment
    {
        $this->ensureItems($items);

        return DB::transaction(function () use ($data, $items): StockAdjustment {
            $adjustment = StockAdjustment::create([...$data, 'created_by' => auth()->id()]);

            foreach ($items as $row) {
                $item = $adjustment->items()->create($row);

                $this->ledger->moveStock(
                    $item->variant_id, $adjustment->warehouse_id, $item->qty, StockMovementType::Adjustment,
                    $item, note: $adjustment->reason->getLabel(), movedAt: $adjustment->adjusted_at,
                );
            }

            return $adjustment;
        });
    }

    /**
     * Isi baris opname draft dengan semua varian yang punya saldo di gudang opname.
     */
    public function fillOpnameFromStock(StockOpname $opname): int
    {
        return DB::transaction(function () use ($opname): int {
            $opname = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            $this->ensureDraft($opname);
            $existing = $opname->items()->pluck('variant_id')->all();
            $balances = StockBalance::query()
                ->where('warehouse_id', $opname->warehouse_id)
                ->whereNotIn('variant_id', $existing)
                ->get();

            foreach ($balances as $balance) {
                $opname->items()->create([
                    'variant_id' => $balance->variant_id,
                    'system_qty' => $this->ledger->stockQtyAt($balance->variant_id, $opname->warehouse_id, $opname->counted_at),
                    'counted_qty' => max($this->ledger->stockQtyAt($balance->variant_id, $opname->warehouse_id, $opname->counted_at), 0),
                ]);
            }

            return $balances->count();
        });
    }

    /**
     * Posting opname: system_qty dihitung pada counted_at, selisih -> ledger(opname).
     */
    public function postOpname(StockOpname $opname): void
    {
        DB::transaction(function () use ($opname): void {
            $opname = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            $this->ensureDraft($opname);

            if ($opname->items()->doesntExist()) {
                throw new BusinessRuleException('Opname belum punya baris hitung.');
            }

            if ($opname->counted_at->isFuture()) {
                throw new BusinessRuleException('Waktu opname tidak boleh di masa depan.');
            }
            foreach ($opname->items()->orderBy('variant_id')->get() as $item) {
                $this->ledger->lockBalance('stock', $item->variant_id, $opname->warehouse_id);
                $this->ledger->ensureNoLaterCount('stock', $item->variant_id, $opname->warehouse_id, $opname->counted_at);
                $item->update(['system_qty' => $this->ledger->stockQtyAt($item->variant_id, $opname->warehouse_id, $opname->counted_at)]);
                $item->refresh();

                if ($item->diff !== 0) {
                    $this->ledger->moveStock(
                        $item->variant_id, $opname->warehouse_id, $item->diff, StockMovementType::Opname,
                        $item, movedAt: $opname->counted_at,
                    );
                }
            }

            $opname->update(['status' => StockOpnameStatus::Posted, 'posted_at' => now()]);
        });
    }

    public function openingStock(int $variantId, int $warehouseId, int $qty, int|float|string|null $unitCost, string $movedAt, ?string $note = null): void
    {
        $this->ledger->moveStock($variantId, $warehouseId, $qty, StockMovementType::Opening, null, $unitCost, $note, $movedAt);
    }

    public function openingMaterial(int $materialId, int $warehouseId, int|float|string $qty, int|float|string|null $unitCost, string $movedAt, ?string $note = null): void
    {
        $this->ledger->moveMaterial($materialId, $warehouseId, $qty, MaterialMovementType::Opening, null, $unitCost, $note, $movedAt);
    }

    /**
     * Koreksi saldo bahan (+/-) tanpa dokumen.
     */
    public function adjustMaterial(int $materialId, int $warehouseId, int|float|string $qty, string $movedAt, ?string $note = null): void
    {
        $this->ledger->moveMaterial($materialId, $warehouseId, $qty, MaterialMovementType::Adjustment, null, null, $note, $movedAt);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ensureItems(array $items): void
    {
        if ($items === []) {
            throw new BusinessRuleException('Minimal satu baris barang harus diisi.');
        }
    }

    private function ensureDraft(StockOpname $opname): void
    {
        if ($opname->status !== StockOpnameStatus::Draft) {
            throw new BusinessRuleException('Opname sudah diposting.');
        }
    }
}
