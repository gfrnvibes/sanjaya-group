<?php

namespace App\Services;

use App\Enums\GoodsReceiptSource;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\SupplierBillLine;
use App\Models\SupplierWholesalePrice;
use App\Models\VariantCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN SUPPLIER produk beli jadi (beli putus + dikirim langsung supplier):
 *  - TAGIHAN PEMBELIAN: Barang Masuk sumber Beli Jadi dengan supplier & qty > 0 -> stok masuk konveksi dan
 *    tagihan qty x harga beli pada tanggal terima. Barang Masuk tanpa supplier = dibayar tunai (tanpa tagihan).
 *  - Penjualan beli jadi mengambil STOK KONVEKSI dulu (tanpa tagihan; sudah dibeli). Kekurangannya
 *    (dropship_qty) DIKIRIM LANGSUNG SUPPLIER: tanpa mutasi stok, jadi TAGIHAN BARANG KELUAR ke supplier
 *    Model Produk dengan harga beli (HPP tanpa packing) pada tanggal pesanan — hanya untuk pesanan Selesai.
 *  - Titipan grosir bordir: aturan stok sama; bagian yang dikirim supplier ditagih harga grosir ke supplier grosir.
 *  - Retur masuk stok konveksi dan TIDAK mengurangi tagihan supplier.
 * Hanya pembelian / pesanan mulai tanggal pengaturan supplier_billing_start yang ditagihkan.
 */
class SupplierBilling
{
    public const DEFAULT_START = '2026-08-01';

    public function __construct(private StockLedger $ledger) {}

    public function startDate(): string
    {
        return Carbon::parse(Setting::valueOf('supplier_billing_start', self::DEFAULT_START))->toDateString();
    }

    /**
     * Harga beli per pcs varian beli jadi pada tanggal: komponen harga beli (material_cost) HPP terbaru yang berlaku.
     */
    public function purchasePrice(ProductVariant $variant, CarbonInterface|string $date): ?float
    {
        $cost = VariantCost::query()
            ->where('variant_id', $variant->id)
            ->whereDate('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_from')
            ->value('material_cost');

        return $cost === null ? null : (float) $cost;
    }

    // ---------------------------------------------------------------- stok penjualan

    /**
     * Catat stok keluar 1 baris penjualan: beli jadi mengambil stok konveksi yang tersedia pada tanggal pesanan,
     * sisanya dikirim langsung supplier (dropship_qty, tanpa mutasi stok). Produk produksi selalu dari stok.
     */
    public function shipFromStock(SalesOrderItem $item): void
    {
        $item->loadMissing(['salesOrder', 'variant.model']);
        $order = $item->salesOrder;

        if ($order->status === SalesOrderStatus::Cancelled) {
            return;
        }

        $fromStock = $item->qty;

        if ($item->variant?->isReadyMade()) {
            $this->ledger->lockBalance('stock', $item->variant_id, $order->warehouse_id);
            $fromStock = min($item->qty, $this->ledger->availableStockAt($item->variant_id, $order->warehouse_id, $order->order_date));
        }

        if ($fromStock > 0) {
            $this->ledger->moveStock(
                $item->variant_id, $order->warehouse_id, -$fromStock, StockMovementType::SaleOut,
                $item, $item->hpp_unit, movedAt: $order->order_date,
            );
        }

        $item->update(['dropship_qty' => $item->qty - $fromStock]);
    }

    /**
     * Kembalikan stok yang pernah dikeluarkan baris penjualan (batal / koreksi qty). Bagian dikirim supplier tidak punya stok.
     */
    public function returnToStock(SalesOrderItem $item, string $note = 'Koreksi penjualan'): void
    {
        $item->loadMissing('salesOrder');
        $posted = -(int) StockMovement::query()->where('source_type', $item->getMorphClass())->where('source_id', $item->id)->sum('qty');

        if ($posted > 0) {
            $this->ledger->moveStock(
                $item->variant_id, $item->salesOrder->warehouse_id, $posted, StockMovementType::SaleCancel,
                $item, $item->hpp_unit, $note, $item->salesOrder->order_date,
            );
        }
    }

    // ---------------------------------------------------------------- tagihan

    /**
     * Tagihan Pembelian untuk 1 baris Barang Masuk Beli Jadi ber-supplier. Aman dipanggil ulang.
     */
    public function purchased(GoodsReceiptItem $item): void
    {
        $item->loadMissing('receipt');
        $receipt = $item->receipt;

        if ($receipt?->source !== GoodsReceiptSource::BeliJadi || $receipt->supplier_id === null || $item->qty <= 0 || $item->unit_cost === null
            || $receipt->received_at->toDateString() < $this->startDate()
            || SupplierBillLine::query()->where('goods_receipt_item_id', $item->id)->exists()) {
            return;
        }

        SupplierBillLine::create([
            'kind' => SupplierBillLine::KIND_PURCHASE,
            'goods_receipt_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'supplier_id' => $receipt->supplier_id,
            'billed_on' => $receipt->received_at,
            'qty' => $item->qty,
            'unit_cost' => $item->unit_cost,
        ]);
    }

    /**
     * Supplier / qty Barang Masuk berubah: Tagihan Pembelian dokumen itu dibuat ulang.
     */
    public function changeReceiptSupplier(GoodsReceipt $receipt): void
    {
        SupplierBillLine::query()->whereIn('goods_receipt_item_id', $receipt->items()->select('id'))->delete();
        $receipt->items()->get()->each(fn (GoodsReceiptItem $item) => $this->purchased($item->setRelation('receipt', $receipt)));
    }

    /**
     * Tagihan Barang Keluar untuk 1 baris pesanan Selesai: bagian yang dikirim langsung supplier. Aman dipanggil ulang.
     */
    public function bill(SalesOrderItem $item): void
    {
        $item->loadMissing(['salesOrder', 'variant.model']);
        $order = $item->salesOrder;

        if (! in_array($order?->status, [SalesOrderStatus::Completed, SalesOrderStatus::Returned], true)
            || ! $item->variant?->isReadyMade()
            || $item->dropship_qty <= 0
            || $order->order_date->toDateString() < $this->startDate()
            || $item->supplierBillLines()->exists()) {
            return;
        }

        // Titipan grosir: harga grosir (dihitung dari total SKU per pesanan) ke supplier grosir.
        $wholesale = $item->is_consignment ? $this->wholesaleFor($item->variant, 2_147_483_647, $order->order_date) : null;
        $supplierId = $wholesale['supplier_id'] ?? $item->variant->model?->supplier_id;
        $unitCost = $item->is_consignment ? (float) $item->hpp_unit : $this->purchasePrice($item->variant, $order->order_date);

        SupplierBillLine::create([
            'kind' => SupplierBillLine::KIND_GOODS_OUT,
            'sales_order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'supplier_id' => $supplierId,
            'billed_on' => $order->order_date,
            'qty' => $item->dropship_qty,
            'unit_cost' => $supplierId === null ? null : $unitCost, // tanpa supplier = menunggu supplier Model Produk
            'is_consignment' => $item->is_consignment,
        ]);
    }

    /**
     * Batalkan Tagihan Barang Keluar baris pesanan (batal / koreksi).
     */
    public function reverse(SalesOrderItem $item): void
    {
        $item->supplierBillLines()->delete();
    }

    public function rebill(SalesOrderItem $item): void
    {
        $this->reverse($item);
        $this->bill($item->fresh());
    }

    /**
     * Supplier Model Produk diganti: Tagihan Barang Keluar normal varian model itu pindah ke supplier baru
     * dengan harga beli pada tanggal pesanannya. Titipan grosir tetap ke supplier grosirnya.
     */
    public function modelSupplierChanged(ProductModel $model): void
    {
        SupplierBillLine::query()
            ->with(['variant', 'salesOrderItem.salesOrder'])
            ->where('kind', SupplierBillLine::KIND_GOODS_OUT)
            ->whereIn('variant_id', $model->variants()->withTrashed()->select('id'))
            ->where('is_consignment', false)
            ->get()
            ->each(fn (SupplierBillLine $line) => $line->update([
                'supplier_id' => $model->supplier_id,
                'unit_cost' => $model->supplier_id === null || $line->variant === null
                    ? null
                    : $this->purchasePrice($line->variant, $line->salesOrderItem?->salesOrder?->order_date ?? $line->billed_on),
            ]));
    }

    /**
     * Harga grosir bordir untuk total qty SKU dalam 1 pesanan: supplier Model Produk diutamakan,
     * tingkat harga = min. qty tertinggi yang terpenuhi, berlaku pada tanggal pesanan.
     *
     * @return array{supplier_id: int, unit_price: float, min_qty: int}|null
     */
    public function wholesaleFor(ProductVariant $variant, int $qty, CarbonInterface|string $date): ?array
    {
        $price = SupplierWholesalePrice::query()
            ->where('model_id', $variant->model_id)
            ->where('min_qty', '<=', $qty)
            ->whereDate('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByRaw('supplier_id = ? DESC', [$variant->model?->supplier_id ?? 0])
            ->orderByDesc('min_qty')
            ->orderByDesc('effective_from')
            ->first();

        return $price === null ? null : ['supplier_id' => $price->supplier_id, 'unit_price' => (float) $price->unit_price, 'min_qty' => $price->min_qty];
    }

    /**
     * Apakah model punya harga grosir (produk bordir grosir).
     */
    public function hasWholesale(ProductVariant $variant): bool
    {
        return SupplierWholesalePrice::query()->where('model_id', $variant->model_id)->exists();
    }

    // ---------------------------------------------------------------- hitung ulang

    /**
     * Hitung ulang dari awal: (1) alokasi stok vs dikirim supplier setiap penjualan beli jadi diulang urut tanggal
     * (selisih stok dikoreksi lewat mutasi di kartu stok), (2) semua tagihan dibuat ulang. Pembayaran tidak berubah.
     *
     * @return array{lines: int, purchase: int, goods_out: int, corrected: int, no_supplier: int, no_price: int}
     */
    public function rebuild(?string $from = null): array
    {
        $from = Carbon::parse($from ?? $this->startDate())->toDateString();
        Setting::updateOrCreate(['key' => 'supplier_billing_start'], ['value' => $from]);

        return DB::transaction(function (): array {
            $corrected = $this->reallocate();

            SupplierBillLine::query()->delete();
            StockLot::query()->delete(); // lot FIFO lama tidak dipakai lagi

            GoodsReceiptItem::query()->with('receipt')
                ->whereHas('receipt', fn ($query) => $query->where('source', GoodsReceiptSource::BeliJadi))
                ->get()
                ->each(fn (GoodsReceiptItem $item) => $this->purchased($item));

            SalesOrderItem::query()->with(['salesOrder', 'variant.model'])
                ->where('dropship_qty', '>', 0)
                ->whereHas('salesOrder', fn ($query) => $query->whereIn('status', [SalesOrderStatus::Completed, SalesOrderStatus::Returned]))
                ->get()
                ->each(fn (SalesOrderItem $item) => $this->bill($item));

            return [
                'lines' => SupplierBillLine::query()->count(),
                'purchase' => SupplierBillLine::query()->where('kind', SupplierBillLine::KIND_PURCHASE)->count(),
                'goods_out' => SupplierBillLine::query()->where('kind', SupplierBillLine::KIND_GOODS_OUT)->count(),
                'corrected' => $corrected,
                'no_supplier' => (int) SupplierBillLine::query()->whereNull('supplier_id')->sum('qty'),
                'no_price' => (int) SupplierBillLine::query()->whereNotNull('supplier_id')->whereNull('unit_cost')->sum('qty'),
            ];
        });
    }

    /**
     * Ulangi alokasi stok penjualan beli jadi urut tanggal (stok konveksi dulu, sisanya dikirim supplier) dan koreksi
     * kartu stok bila mutasi penjualan yang tercatat berbeda. Mengembalikan jumlah baris yang dikoreksi.
     */
    public function reallocate(): int
    {
        $corrected = 0;
        $morph = (new SalesOrderItem)->getMorphClass();

        $items = SalesOrderItem::query()
            ->select('sales_order_items.*')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('product_variants', 'product_variants.id', '=', 'sales_order_items.variant_id')
            ->join('product_models', 'product_models.id', '=', 'product_variants.model_id')
            ->where('product_models.kind', 'beli_jadi')
            ->where('sales_orders.status', '!=', SalesOrderStatus::Cancelled)
            ->with('salesOrder')
            ->orderBy('sales_orders.order_date')
            ->orderBy('sales_order_items.id')
            ->get();

        foreach ($items->groupBy(fn (SalesOrderItem $item): string => $item->variant_id.'|'.$item->salesOrder->warehouse_id) as $group) {
            /** @var SalesOrderItem $first */
            $first = $group->first();
            $variantId = $first->variant_id;
            $warehouseId = $first->salesOrder->warehouse_id;
            $this->ledger->lockBalance('stock', $variantId, $warehouseId);

            // Mutasi selain penjualan (barang masuk, retur, stok awal, penyesuaian, opname), urut waktu.
            $others = StockMovement::query()
                ->where('variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->where(fn ($query) => $query->where('source_type', '!=', $morph)->orWhereNull('source_type'))
                ->orderBy('moved_at')
                ->orderBy('id')
                ->get(['moved_at', 'qty']);
            $posted = StockMovement::query()
                ->where('source_type', $morph)
                ->whereIn('source_id', $group->modelKeys())
                ->selectRaw('source_id, SUM(qty) AS qty')
                ->groupBy('source_id')
                ->pluck('qty', 'source_id');

            $balance = 0;
            $cursor = 0;

            foreach ($group as $item) {
                $at = $item->salesOrder->order_date;

                while ($cursor < $others->count() && $others[$cursor]->moved_at->lte($at)) {
                    $balance += (int) $others[$cursor]->qty;
                    $cursor++;
                }

                $fromStock = max(0, min($item->qty, $balance));
                $balance -= $fromStock;
                $diff = -$fromStock - (int) ($posted[$item->id] ?? 0);

                if ($diff !== 0) {
                    $this->ledger->moveStock(
                        $variantId, $warehouseId, $diff, $diff < 0 ? StockMovementType::SaleOut : StockMovementType::SaleCancel,
                        $item, $item->hpp_unit, 'Koreksi: bagian dikirim langsung supplier / diambil dari stok', $at,
                    );
                }

                if ($diff !== 0 || $item->dropship_qty !== $item->qty - $fromStock) {
                    $item->update(['dropship_qty' => $item->qty - $fromStock]);
                    $corrected++;
                }
            }
        }

        return $corrected;
    }
}
