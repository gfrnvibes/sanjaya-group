<?php

namespace App\Services;

use App\Enums\ResellerInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Simpan penjualan (snapshot harga + stok keluar), batal, dan retur.
 */
class SalesOrderService
{
    public function __construct(
        private StockLedger $ledger,
        private PricingService $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items  variant_id, qty, revenue, sku_raw
     */
    public function create(array $data, array $items): SalesOrder
    {
        if ($items === []) {
            throw new BusinessRuleException('Minimal satu baris produk harus diisi.');
        }

        return DB::transaction(function () use ($data, $items): SalesOrder {
            $order = SalesOrder::create([...$data, 'created_by' => auth()->id()]);
            $ownerId = Store::query()->with('reseller')->findOrFail($order->store_id)->reseller->owner_id;

            foreach ($items as $row) {
                $snapshot = $this->pricing->snapshot((int) $row['variant_id'], $ownerId, $order->order_date);
                $item = $order->items()->create([
                    'variant_id' => $row['variant_id'],
                    'sku_raw' => $row['sku_raw'] ?? null,
                    'qty' => $row['qty'],
                    'revenue' => $row['revenue'] ?? null,
                    ...$snapshot,
                ]);

                if ($order->status !== SalesOrderStatus::Cancelled) {
                    $this->ledger->moveStock(
                        $item->variant_id, $order->warehouse_id, -$item->qty, StockMovementType::SaleOut,
                        $item, $item->hpp_unit, movedAt: $order->order_date,
                    );
                }
            }

            return $order;
        });
    }

    /**
     * Batal pesanan: stok kembali (sale_cancel) dan baris dilepas dari tagihan draft.
     */
    public function cancel(SalesOrder $order): void
    {
        if ($order->status === SalesOrderStatus::Cancelled) {
            throw new BusinessRuleException('Pesanan sudah dibatalkan.');
        }

        $order->loadMissing(['items.returns', 'items.invoice']);

        foreach ($order->items as $item) {
            if ($item->returns->isNotEmpty()) {
                throw new BusinessRuleException('Pesanan yang sudah ada retur tidak bisa dibatalkan.');
            }

            if ($item->invoice && $item->invoice->status === ResellerInvoiceStatus::Issued) {
                throw new BusinessRuleException("Pesanan sudah masuk tagihan terbit {$item->invoice->invoice_no}.");
            }
        }

        DB::transaction(function () use ($order): void {
            foreach ($order->items as $item) {
                $this->ledger->moveStock(
                    $item->variant_id, $order->warehouse_id, $item->qty, StockMovementType::SaleCancel,
                    $item, $item->hpp_unit,
                );
                $item->update(['reseller_invoice_id' => null]);
            }

            $order->update(['status' => SalesOrderStatus::Cancelled]);
        });
    }

    public function returnableQty(SalesOrderItem $item): int
    {
        return $item->qty - (int) $item->returns()->sum('qty');
    }

    /**
     * @param  array<string, mixed>  $data  returned_at, qty, restock, warehouse_id, credit_reseller, note
     */
    public function registerReturn(SalesOrderItem $item, array $data): SalesReturn
    {
        $order = $item->salesOrder;

        if ($order->status === SalesOrderStatus::Cancelled) {
            throw new BusinessRuleException('Pesanan batal tidak bisa diretur.');
        }

        $returnable = $this->returnableQty($item);

        if ((int) $data['qty'] > $returnable) {
            throw new BusinessRuleException("Qty retur melebihi sisa yang bisa diretur ({$returnable}).");
        }

        return DB::transaction(function () use ($item, $order, $data): SalesReturn {
            $return = $item->returns()->create([
                ...$data,
                'warehouse_id' => ($data['restock'] ?? false) ? ($data['warehouse_id'] ?? $order->warehouse_id) : null,
                'created_by' => auth()->id(),
            ]);

            if ($return->restock) {
                $this->ledger->moveStock(
                    $item->variant_id, $return->warehouse_id, $return->qty, StockMovementType::ReturnIn,
                    $return, $item->hpp_unit, movedAt: $return->returned_at,
                );
            }

            $fullyReturned = $order->items()->get()->every(fn (SalesOrderItem $line): bool => $this->returnableQty($line) === 0);

            if ($fullyReturned) {
                $order->update(['status' => SalesOrderStatus::Returned]);
            }

            return $return;
        });
    }
}
