<?php

namespace App\Services;

use App\Enums\ResellerInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Simpan penjualan (snapshot harga + stok keluar), batal, dan retur.
 */
class SalesOrderService
{
    public function __construct(
        private StockLedger $ledger,
        private PricingService $pricing,
        private SupplierBilling $supplierBilling,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items  variant_id, qty, revenue, sku_raw;
     *                                             consignment_price = titipan grosir bordir (harga ke reseller = harga supplier, tanpa laba)
     */
    public function create(array $data, array $items): SalesOrder
    {
        if ($items === []) {
            throw new BusinessRuleException('Minimal satu baris produk harus diisi.');
        }

        return DB::transaction(function () use ($data, $items): SalesOrder {
            $order = SalesOrder::create([...$data, 'created_by' => auth()->id()]);
            $store = Store::query()->with('reseller.owner')->findOrFail($order->store_id);
            if (! $store->is_active || $store->trashed() || ! $store->reseller?->is_active || $store->reseller->trashed() || ! $store->reseller->owner?->is_active || $store->reseller->owner->trashed()) {
                throw new BusinessRuleException('Toko, reseller, atau pemilik tidak aktif.');
            }
            $ownerId = $store->reseller->owner_id;

            foreach ($items as $row) {
                if ((int) $row['qty'] < 1 || (float) $row['qty'] !== (float) (int) $row['qty']) {
                    throw new BusinessRuleException('Qty penjualan harus bilangan bulat minimal 1.');
                }
                $variant = ProductVariant::query()->find($row['variant_id']);
                if (! $variant?->is_active) {
                    throw new BusinessRuleException('SKU tidak ditemukan atau tidak aktif.');
                }
                $snapshot = $this->pricing->snapshot((int) $row['variant_id'], $ownerId, $order->order_date);

                if (isset($row['consignment_price'])) {
                    // Titipan: reseller membayar persis harga supplier; HPP = harga itu (laba konveksi 0).
                    $snapshot = [...$snapshot, 'tier_adjustment_pct' => 0, 'reseller_unit_price' => (float) $row['consignment_price'], 'hpp_unit' => (float) $row['consignment_price']];
                }

                $item = $order->items()->create([
                    'variant_id' => $row['variant_id'],
                    'sku_raw' => $row['sku_raw'] ?? null,
                    'qty' => $row['qty'],
                    'revenue' => $row['revenue'] ?? null,
                    'is_consignment' => isset($row['consignment_price']),
                    ...$snapshot,
                ]);

                // Beli jadi: stok konveksi dulu, kekurangannya dikirim langsung supplier (tanpa mutasi stok).
                $this->supplierBilling->shipFromStock($item);
                $this->supplierBilling->bill($item);
            }

            return $order;
        });
    }

    /**
     * Batal pesanan: stok kembali (sale_cancel) dan baris dilepas dari tagihan draft.
     */
    public function cancel(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
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

            foreach ($order->items as $item) {
                // Hanya bagian yang dulu diambil dari stok yang kembali ke stok (bagian dikirim supplier tidak).
                $this->supplierBilling->returnToStock($item->setRelation('salesOrder', $order), 'Batal jual');
                $item->update(['reseller_invoice_id' => null]);
                $this->supplierBilling->reverse($item);
            }

            $order->update(['status' => SalesOrderStatus::Cancelled]);
        });
    }

    public function completePending(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== SalesOrderStatus::Pending) {
                throw new BusinessRuleException('Status pesanan sudah berubah. Muat ulang halaman.');
            }
            $locked->update(['status' => SalesOrderStatus::Completed]);
            $locked->items()->get()->each(fn (SalesOrderItem $item) => $this->supplierBilling->bill($item));
        });
        $order->refresh();
    }

    /**
     * Koreksi qty/pendapatan pesanan impor. $consignmentPrice = harga grosir bordir bila qty baru masuk grosir
     * (null = harga normal); harga ikut berubah bila status grosir/harganya berubah.
     */
    public function reviseImported(SalesOrder $order, int $qty, ?float $revenue, ?float $consignmentPrice = null): void
    {
        DB::transaction(function () use ($order, $qty, $revenue, $consignmentPrice): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $item = $order->items()->lockForUpdate()->sole();
            if ($order->import_batch_id === null || $order->status === SalesOrderStatus::Cancelled || $qty < 1 || $item->reseller_invoice_id !== null || $item->returns()->exists()) {
                throw new BusinessRuleException('Pesanan batal, sudah ditagih, atau sudah diretur tidak bisa dikoreksi lewat impor.');
            }
            $delta = $qty - $item->qty;
            $item->setRelation('salesOrder', $order);
            if ($delta !== 0 && $item->variant()->first()?->isReadyMade()) {
                // Beli jadi: lepas stok lama lalu alokasikan ulang (stok dulu, sisanya dikirim supplier).
                $this->supplierBilling->returnToStock($item, 'Koreksi jumlah impor');
                $item->update(['qty' => $qty, 'dropship_qty' => 0, 'revenue' => $revenue]);
                $this->supplierBilling->shipFromStock($item);
            } else {
                if ($delta !== 0) {
                    $this->ledger->moveStock($item->variant_id, $order->warehouse_id, -$delta, StockMovementType::Adjustment, $item, $item->hpp_unit, 'Koreksi jumlah impor', $order->order_date);
                }
                $item->update(['qty' => $qty, 'revenue' => $revenue]);
            }

            $repriced = $item->is_consignment !== ($consignmentPrice !== null)
                || ($consignmentPrice !== null && round((float) $item->reseller_unit_price, 2) !== round($consignmentPrice, 2));

            if ($repriced) {
                $order->loadMissing('store.reseller');
                $snapshot = $this->pricing->snapshot($item->variant_id, $order->store->reseller->owner_id, $order->order_date);
                $item->update($consignmentPrice === null
                    ? [...$snapshot, 'is_consignment' => false]
                    : [...$snapshot, 'tier_adjustment_pct' => 0, 'reseller_unit_price' => $consignmentPrice, 'hpp_unit' => $consignmentPrice, 'is_consignment' => true]);
            }

            $this->supplierBilling->rebill($item);
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
        return DB::transaction(function () use ($item, $data): SalesReturn {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($item->sales_order_id);
            $item = SalesOrderItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! isset($data['qty']) || (int) $data['qty'] < 1 || (float) $data['qty'] !== (float) (int) $data['qty']) {
                throw new BusinessRuleException('Qty retur minimal 1.');
            }
            if (Carbon::parse($data['returned_at'])->lt($order->order_date)) {
                throw new BusinessRuleException('Tanggal retur tidak boleh sebelum tanggal pesanan.');
            }

            if ($order->status === SalesOrderStatus::Cancelled) {
                throw new BusinessRuleException('Pesanan batal tidak bisa diretur.');
            }

            $returnable = $this->returnableQty($item);

            if ((int) $data['qty'] > $returnable) {
                throw new BusinessRuleException("Qty retur melebihi sisa yang bisa diretur ({$returnable}).");
            }

            $refund = $data['refund_amount'] ?? ($item->revenue === null ? null : round((float) $item->revenue * (int) $data['qty'] / $item->qty, 2));
            if ($refund !== null && ($refund < 0 || ($item->revenue !== null && (float) $item->returns()->sum('refund_amount') + $refund > (float) $item->revenue + 0.001))) {
                throw new BusinessRuleException('Nilai refund tidak boleh negatif atau melebihi pendapatan pesanan.');
            }
            $return = $item->returns()->create([
                ...$data,
                'refund_amount' => $refund,
                'warehouse_id' => ($data['restock'] ?? false) ? ($data['warehouse_id'] ?? $order->warehouse_id) : null,
                'created_by' => auth()->id(),
            ]);

            if ($return->restock) {
                $this->ledger->moveStock(
                    $item->variant_id, $return->warehouse_id, $return->qty, StockMovementType::ReturnIn,
                    $return, $item->hpp_unit, movedAt: $return->returned_at,
                );
            }

            // Retur (termasuk barang yang dulu dikirim langsung supplier) masuk stok konveksi; tagihan supplier tidak berubah.

            $fullyReturned = $order->items()->get()->every(fn (SalesOrderItem $line): bool => $this->returnableQty($line) === 0);

            if ($fullyReturned) {
                $order->update(['status' => SalesOrderStatus::Returned]);
            }

            return $return;
        });
    }
}
