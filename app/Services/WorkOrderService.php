<?php

namespace App\Services;

use App\Enums\MaterialMovementType;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SPK: dipotong -> bahan keluar per baris resep; selesai -> barang jadi masuk (qty_good).
 */
class WorkOrderService
{
    public function __construct(
        private StockLedger $ledger,
        private CostingService $costing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items  variant_id, qty_cut
     */
    public function create(array $data, array $items): WorkOrder
    {
        if ($items === []) {
            throw new BusinessRuleException('Minimal satu varian harus dipotong.');
        }

        return DB::transaction(function () use ($data, $items): WorkOrder {
            $workOrder = WorkOrder::create([...$data, 'status' => WorkOrderStatus::Cutting, 'created_by' => auth()->id()]);

            foreach ($items as $row) {
                $variant = ProductVariant::query()->findOrFail($row['variant_id']);
                $cost = $this->costing->calculate($variant, $workOrder->cut_date);

                $item = $workOrder->items()->create([
                    'variant_id' => $variant->id,
                    'qty_cut' => $row['qty_cut'],
                    'material_cost_snapshot' => $cost['material_cost'],
                    'labor_cost_snapshot' => $cost['labor_cost'],
                ]);

                foreach ($cost['materials'] as $line) {
                    $this->ledger->moveMaterial(
                        $line['material']->id, $workOrder->warehouse_id, -round($line['qty_per_unit'] * $item->qty_cut, 3),
                        MaterialMovementType::ProductionOut, $item, $line['unit_cost'],
                        "SPK {$workOrder->code} - {$variant->sku}", $workOrder->cut_date,
                    );
                }
            }

            return $workOrder;
        });
    }

    public function startSewing(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder): void {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            if ($locked->status !== WorkOrderStatus::Cutting) {
                throw new BusinessRuleException('Hanya SPK berstatus Dipotong yang bisa masuk jahit.');
            }
            $locked->update(['status' => WorkOrderStatus::Sewing]);
        });
        $workOrder->refresh();
    }

    /**
     * @param  array<int, array{qty_good: int, qty_reject: int}>  $results  key = work_order_item_id
     */
    public function complete(WorkOrder $workOrder, array $results, string $finishedDate): void
    {
        DB::transaction(function () use ($workOrder, $results, $finishedDate): void {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            if (! in_array($workOrder->status, [WorkOrderStatus::Cutting, WorkOrderStatus::Sewing], true)) {
                throw new BusinessRuleException('SPK sudah selesai atau batal.');
            }

            if (Carbon::parse($finishedDate)->lt($workOrder->cut_date)) {
                throw new BusinessRuleException('Tanggal selesai tidak boleh sebelum tanggal potong.');
            }
            foreach ($workOrder->items as $item) {
                $result = $results[$item->id] ?? throw new BusinessRuleException('Hasil semua varian wajib diisi.');

                if ($result['qty_good'] < 0 || $result['qty_reject'] < 0 || $item->qty_cut !== $result['qty_good'] + $result['qty_reject']) {
                    throw new BusinessRuleException("Qty jadi + reject {$item->variant->sku} harus sama dengan qty potong.");
                }

                $item->update(['qty_good' => $result['qty_good'], 'qty_reject' => $result['qty_reject']]);

                if ($item->qty_good > 0) {
                    $this->ledger->moveStock(
                        $item->variant_id, $workOrder->goodsWarehouseId(), $item->qty_good, StockMovementType::ProductionIn,
                        $item, (float) $item->material_cost_snapshot + (float) $item->labor_cost_snapshot,
                        "SPK {$workOrder->code}", Carbon::parse($finishedDate),
                    );
                }
            }

            $workOrder->update(['status' => WorkOrderStatus::Done, 'finished_date' => $finishedDate]);
        });
    }

    /**
     * Batal SPK. Bahan yang sudah dipotong tidak dikembalikan otomatis (pakai penyesuaian bahan bila perlu).
     */
    public function cancel(WorkOrder $workOrder): void
    {
        if (in_array($workOrder->status, [WorkOrderStatus::Done, WorkOrderStatus::Cancelled], true)) {
            throw new BusinessRuleException('SPK sudah selesai atau batal.');
        }

        DB::transaction(function () use ($workOrder): void {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            if (! in_array($locked->status, [WorkOrderStatus::Cutting, WorkOrderStatus::Sewing], true)) {
                throw new BusinessRuleException('SPK sudah selesai atau batal.');
            }
            $locked->update(['status' => WorkOrderStatus::Cancelled]);
        });
        $workOrder->refresh();
    }
}
