<?php

namespace App\Services;

use App\Enums\MaterialMovementType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\MaterialBalance;
use App\Models\MaterialMovement;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu menulis ledger stok barang jadi & bahan.
 * Saldo (*_balances) diperbarui trigger database.
 */
class StockLedger
{
    public function moveStock(
        int $variantId,
        int $warehouseId,
        int $qty,
        StockMovementType $type,
        ?Model $source = null,
        int|float|string|null $unitCost = null,
        ?string $note = null,
        CarbonInterface|string|null $movedAt = null,
    ): StockMovement {
        return DB::transaction(function () use ($variantId, $warehouseId, $qty, $type, $source, $unitCost, $note, $movedAt): StockMovement {
            $this->ensureWarehouse($warehouseId, goods: true);
            $this->lockBalance('stock', $variantId, $warehouseId);
            $this->ensureNoLaterCount('stock', $variantId, $warehouseId, $movedAt ?? now(), $type !== StockMovementType::Opname);

            // Penjualan tetap dicatat dan ditagih meskipun stok barang jadi menjadi negatif.
            return StockMovement::create([
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'moved_at' => $movedAt ?? now(),
                'qty' => $qty,
                'movement_type' => $type,
                'unit_cost' => $unitCost,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function moveMaterial(
        int $materialId,
        int $warehouseId,
        int|float|string $qty,
        MaterialMovementType $type,
        ?Model $source = null,
        int|float|string|null $unitCost = null,
        ?string $note = null,
        CarbonInterface|string|null $movedAt = null,
    ): MaterialMovement {
        return DB::transaction(function () use ($materialId, $warehouseId, $qty, $type, $source, $unitCost, $note, $movedAt): MaterialMovement {
            $this->ensureWarehouse($warehouseId, goods: false);
            $this->lockBalance('material', $materialId, $warehouseId);
            $this->ensureNoLaterCount('material', $materialId, $warehouseId, $movedAt ?? now(), $type !== MaterialMovementType::Opname);

            if ((float) $qty < 0 && (float) $this->materialQty($materialId, $warehouseId) + (float) $qty < -0.0001) {
                throw new BusinessRuleException('Stok bahan tidak cukup. Periksa stok awal/gudang dan lakukan rekonsiliasi.');
            }

            return MaterialMovement::create([
                'material_id' => $materialId,
                'warehouse_id' => $warehouseId,
                'moved_at' => $movedAt ?? now(),
                'qty' => $qty,
                'movement_type' => $type,
                'unit_cost' => $unitCost,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Barang jadi hanya di gudang barang jadi / campuran; bahan hanya di gudang bahan / campuran.
     *
     * @throws BusinessRuleException
     */
    private function ensureWarehouse(int $warehouseId, bool $goods): void
    {
        $warehouse = Warehouse::query()->find($warehouseId, ['id', 'name', 'kind', 'is_active']);

        if ($warehouse === null || ! $warehouse->is_active) {
            throw new BusinessRuleException('Gudang tidak ditemukan atau tidak aktif.');
        }

        if ($goods && ! $warehouse->kind->storesGoods()) {
            throw new BusinessRuleException("\"{$warehouse->name}\" adalah gudang bahan baku, tidak bisa untuk stok barang jadi. Pilih gudang barang jadi.");
        }

        if (! $goods && ! $warehouse->kind->storesMaterials()) {
            throw new BusinessRuleException("\"{$warehouse->name}\" adalah gudang barang jadi, tidak bisa untuk stok bahan. Pilih gudang bahan baku.");
        }
    }

    public function lockBalance(string $kind, int $itemId, int $warehouseId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$kind}:{$itemId}:{$warehouseId}"]);
    }

    public function ensureNoLaterCount(string $kind, int $itemId, int $warehouseId, CarbonInterface|string $at, bool $inclusive = false): void
    {
        $model = $kind === 'stock' ? StockMovement::class : MaterialMovement::class;
        $field = $kind === 'stock' ? 'variant_id' : 'material_id';
        if ($model::query()->where($field, $itemId)->where('warehouse_id', $warehouseId)->where('movement_type', 'opname')->where('moved_at', $inclusive ? '>=' : '>', $at)->exists()) {
            throw new BusinessRuleException('Sudah ada opname pada/setelah waktu tersebut. Gunakan waktu koreksi setelah opname terakhir.');
        }
    }

    /**
     * Stok konveksi yang boleh dipakai pesanan bertanggal $at tanpa membuat saldo minus di tanggal mana pun sesudahnya
     * (pesanan yang diimpor belakangan tidak "memakan" stok yang sudah terpakai penjualan setelahnya). Minimal 0.
     */
    public function availableStockAt(int $variantId, int $warehouseId, CarbonInterface|string $at): int
    {
        $at = Carbon::parse($at);
        $running = $this->stockQtyAt($variantId, $warehouseId, $at);
        $lowest = $running;

        $later = StockMovement::query()
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('moved_at', '>', $at)
            ->orderBy('moved_at')
            ->orderBy('id')
            ->pluck('qty');

        foreach ($later as $qty) {
            $running += (int) $qty;
            $lowest = min($lowest, $running);
        }

        return max(0, $lowest);
    }

    public function stockQtyAt(int $variantId, int $warehouseId, CarbonInterface|string $at): int
    {
        return (int) StockMovement::query()->where('variant_id', $variantId)->where('warehouse_id', $warehouseId)->where('moved_at', '<=', $at)->sum('qty');
    }

    public function materialQtyAt(int $materialId, int $warehouseId, CarbonInterface|string $at): float
    {
        return (float) MaterialMovement::query()->where('material_id', $materialId)->where('warehouse_id', $warehouseId)->where('moved_at', '<=', $at)->sum('qty');
    }

    public function stockQty(int $variantId, int $warehouseId): int
    {
        return (int) StockBalance::query()
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->value('qty');
    }

    public function materialQty(int $materialId, int $warehouseId): string
    {
        return (string) (MaterialBalance::query()
            ->where('material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->value('qty') ?? '0');
    }
}
