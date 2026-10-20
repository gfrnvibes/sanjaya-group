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
        $this->ensureWarehouse($warehouseId, goods: true);

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
        $this->ensureWarehouse($warehouseId, goods: false);

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
    }

    /**
     * Barang jadi hanya di gudang barang jadi / campuran; bahan hanya di gudang bahan / campuran.
     *
     * @throws BusinessRuleException
     */
    private function ensureWarehouse(int $warehouseId, bool $goods): void
    {
        static $warehouses = [];
        $warehouse = $warehouses[$warehouseId] ??= Warehouse::query()->find($warehouseId, ['id', 'name', 'kind']);

        if ($warehouse === null) {
            return;
        }

        if ($goods && ! $warehouse->kind->storesGoods()) {
            throw new BusinessRuleException("\"{$warehouse->name}\" adalah gudang bahan baku, tidak bisa untuk stok barang jadi. Pilih gudang barang jadi.");
        }

        if (! $goods && ! $warehouse->kind->storesMaterials()) {
            throw new BusinessRuleException("\"{$warehouse->name}\" adalah gudang barang jadi, tidak bisa untuk stok bahan. Pilih gudang bahan baku.");
        }
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
