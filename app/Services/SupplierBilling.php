<?php

namespace App\Services;

use App\Enums\GoodsReceiptSource;
use App\Enums\SalesOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\SupplierBillLine;
use App\Models\SupplierWholesalePrice;
use App\Models\VariantCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN SUPPLIER produk beli jadi, FIFO per lot:
 *  - Barang Masuk sumber Beli Jadi (berharga) = 1 lot (supplier, harga beli, sisa qty);
 *  - pesanan Selesai mengambil qty dari lot paling lama -> baris tagihan ke supplier lot itu;
 *    lot habis (stok minus) -> baris "belum teralokasi", dialokasikan otomatis saat ada lot baru;
 *  - pesanan grosir bordir (titipan) ditagihkan ke supplier grosir dengan harga grosir;
 *  - batal / koreksi qty impor membatalkan baris & mengembalikan lot; retur yang kembali ke stok
 *    mengurangi tagihan (baris negatif) dan mengembalikan qty ke lot asal.
 * Hanya pesanan mulai tanggal pengaturan supplier_billing_start yang ditagihkan.
 *
 * Mode "berdasarkan barang keluar" (pengaturan supplier_billing_mode = barang_keluar, sementara selama stok barang jadi
 * belum diinput): tanpa lot/FIFO — tiap pesanan Selesai langsung ditagihkan ke supplier Model Produk dengan harga beli
 * (komponen harga beli HPP) yang berlaku pada tanggal pesanan. Model tanpa supplier = baris "belum ada supplier".
 */
class SupplierBilling
{
    public const DEFAULT_START = '2026-08-01';

    /** Tagihan dari pesanan Selesai (barang keluar) ke supplier Model Produk, tanpa stok. */
    public const MODE_SALES = 'barang_keluar';

    /** Tagihan FIFO per lot Barang Masuk Beli Jadi. */
    public const MODE_STOCK = 'stok';

    public const MODES = [
        self::MODE_SALES => 'Berdasarkan barang keluar (tanpa stok)',
        self::MODE_STOCK => 'Berdasarkan stok Barang Masuk (FIFO)',
    ];

    public function startDate(): string
    {
        return Carbon::parse(Setting::valueOf('supplier_billing_start', self::DEFAULT_START))->toDateString();
    }

    public function mode(): string
    {
        $mode = Setting::valueOf('supplier_billing_mode', self::MODE_STOCK);

        return isset(self::MODES[$mode]) ? $mode : self::MODE_STOCK;
    }

    public function billsBySales(): bool
    {
        return $this->mode() === self::MODE_SALES;
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

    /**
     * Supplier Model Produk diganti (mode barang keluar): baris tagihan normal varian model itu pindah ke supplier baru
     * dengan harga beli pada tanggal pesanannya. Titipan grosir tetap ke supplier grosirnya.
     */
    public function modelSupplierChanged(ProductModel $model): void
    {
        if (! $this->billsBySales()) {
            return;
        }

        SupplierBillLine::query()
            ->with(['variant', 'salesOrderItem.salesOrder'])
            ->whereIn('variant_id', $model->variants()->withTrashed()->select('id'))
            ->where('is_consignment', false)
            ->whereNull('stock_lot_id')
            ->get()
            ->each(fn (SupplierBillLine $line) => $line->update([
                'supplier_id' => $model->supplier_id,
                'unit_cost' => $model->supplier_id === null || $line->variant === null
                    ? null
                    : $this->purchasePrice($line->variant, $line->salesOrderItem?->salesOrder?->order_date ?? $line->billed_on),
            ]));
    }

    /**
     * Barang masuk beli jadi berharga -> lot baru, lalu alokasikan tagihan yang tertunda untuk varian itu.
     */
    public function receive(GoodsReceiptItem $item): ?StockLot
    {
        $item->loadMissing('receipt');
        $receipt = $item->receipt;

        // Mode barang keluar: tanpa lot (stok belum dipakai untuk tagihan); lot dibentuk saat kembali ke mode stok + hitung ulang.
        // Qty 0 = hanya catat harga beli: tidak ada barang, tidak ada lot.
        if ($this->billsBySales() || $item->qty <= 0 || $receipt?->source !== GoodsReceiptSource::BeliJadi || $item->unit_cost === null || StockLot::query()->where('goods_receipt_item_id', $item->id)->exists()) {
            return null;
        }

        $lot = StockLot::create([
            'variant_id' => $item->variant_id,
            'supplier_id' => $receipt->supplier_id,
            'goods_receipt_item_id' => $item->id,
            'received_at' => $receipt->received_at,
            'unit_cost' => $item->unit_cost,
            'qty' => $item->qty,
            'qty_remaining' => $item->qty,
        ]);

        $this->allocatePending($item->variant_id);

        return $lot;
    }

    /**
     * Koreksi supplier Barang Masuk: lot ikut pindah supplier, begitu juga baris tagihan normal dari lot itu
     * (lot tanpa supplier = tunai, tanpa nilai tagihan). Titipan grosir tetap ke supplier grosirnya.
     */
    public function changeReceiptSupplier(GoodsReceipt $receipt): void
    {
        $lots = StockLot::query()->whereIn('goods_receipt_item_id', $receipt->items()->select('id'))->get();

        foreach ($lots as $lot) {
            $lot->update(['supplier_id' => $receipt->supplier_id]);

            SupplierBillLine::query()
                ->where('stock_lot_id', $lot->id)
                ->where('is_consignment', false)
                ->update([
                    'supplier_id' => $receipt->supplier_id,
                    'unit_cost' => $receipt->supplier_id === null ? null : $lot->unit_cost,
                ]);
        }
    }

    /**
     * Tagihan untuk 1 baris pesanan (dipanggil saat pesanan Selesai). Aman dipanggil ulang.
     */
    public function bill(SalesOrderItem $item, bool $record = true): void
    {
        $item->loadMissing(['salesOrder', 'variant.model']);
        $order = $item->salesOrder;

        if (! in_array($order?->status, [SalesOrderStatus::Completed, SalesOrderStatus::Returned], true)
            || ! $item->variant?->isReadyMade()
            || $item->supplierBillLines()->exists()) {
            return;
        }

        $record = $record && $order->order_date->toDateString() >= $this->startDate();
        // Supplier titipan grosir (harga grosir dihitung dari total SKU per pesanan, jadi qty baris ini bisa di bawah minimal qty).
        $wholesale = $item->is_consignment ? $this->wholesaleFor($item->variant, 2_147_483_647, $order->order_date) : null;

        if ($this->billsBySales()) {
            if ($record) {
                $supplierId = $wholesale['supplier_id'] ?? $item->variant->model?->supplier_id;
                $unitCost = $item->is_consignment ? (float) $item->hpp_unit : $this->purchasePrice($item->variant, $order->order_date);
                $this->line($item, $order->order_date, $item->qty, $supplierId, $unitCost, null);
            }

            return;
        }

        $left = $item->qty;

        foreach ($this->openLots($item->variant_id) as $lot) {
            $take = min($left, $lot->qty_remaining);
            $lot->decrement('qty_remaining', $take);
            $left -= $take;

            // Lot tanpa supplier (beli jadi dibayar tunai saat diterima): baris tercatat tanpa supplier & nilai.
            if ($record) {
                $this->line($item, $order->order_date, $take, $wholesale['supplier_id'] ?? $lot->supplier_id, $item->is_consignment ? (float) $item->hpp_unit : (float) $lot->unit_cost, $lot->id);
            }

            if ($left === 0) {
                break;
            }
        }

        if ($left > 0 && $record) {
            // Stok minus: titipan grosir tetap tertagih ke supplier grosirnya; selain itu menunggu lot berikutnya.
            $item->is_consignment && $wholesale !== null
                ? $this->line($item, $order->order_date, $left, $wholesale['supplier_id'], (float) $item->hpp_unit, null)
                : $this->line($item, $order->order_date, $left, null, null, null);
        }
    }

    /**
     * Batalkan tagihan baris pesanan (batal / koreksi): qty kembali ke lot asal.
     */
    public function reverse(SalesOrderItem $item): void
    {
        $lines = $item->supplierBillLines()->lockForUpdate()->get();

        foreach ($lines->whereNotNull('stock_lot_id')->groupBy('stock_lot_id') as $lotId => $lotLines) {
            $net = (int) $lotLines->sum('qty');

            if ($net > 0) {
                StockLot::query()->whereKey($lotId)->increment('qty_remaining', $net);
            }
        }

        $item->supplierBillLines()->delete();
    }

    public function rebill(SalesOrderItem $item): void
    {
        $this->reverse($item);
        $this->bill($item->fresh());
    }

    /**
     * Retur yang kembali ke stok: kurangi tagihan (yang belum teralokasi dulu, lalu lot terbaru) dan kembalikan qty ke lot.
     * Retur tanpa restock (barang rusak/hilang) tetap ditagih supplier, termasuk titipan grosir bordir.
     */
    public function returned(SalesReturn $return): void
    {
        if (! $return->restock) {
            return;
        }

        $left = $return->qty;
        $lines = SupplierBillLine::query()->where('sales_order_item_id', $return->sales_order_item_id)->lockForUpdate()->orderBy('id')->get();

        // Sisa qty per sumber (lot / belum teralokasi) setelah retur sebelumnya.
        $sources = $lines->groupBy(fn (SupplierBillLine $line): string => ($line->stock_lot_id ?? 'x').'|'.($line->supplier_id ?? 'x').'|'.$line->unit_cost.'|'.(int) $line->is_consignment)
            ->map(fn ($group): array => ['line' => $group->firstWhere('qty', '>', 0), 'net' => (int) $group->sum('qty')])
            ->filter(fn (array $source): bool => $source['net'] > 0 && $source['line'] !== null)
            ->sortBy(fn (array $source): array => [$source['line']->stock_lot_id === null ? 0 : 1, -($source['line']->stock_lot_id ?? 0)]);

        foreach ($sources as $source) {
            $take = min($left, $source['net']);
            /** @var SupplierBillLine $line */
            $line = $source['line'];

            SupplierBillLine::create([
                ...$line->only(['sales_order_item_id', 'variant_id', 'supplier_id', 'stock_lot_id', 'unit_cost', 'is_consignment']),
                'sales_return_id' => $return->id,
                'billed_on' => $return->returned_at,
                'qty' => -$take,
            ]);

            if ($line->stock_lot_id !== null) {
                StockLot::query()->whereKey($line->stock_lot_id)->increment('qty_remaining', $take);
            }

            $left -= $take;

            if ($left === 0) {
                break;
            }
        }
    }

    /**
     * Alokasikan baris "belum teralokasi" varian ke lot yang tersedia (FIFO, baris terlama dulu).
     */
    public function allocatePending(int $variantId): void
    {
        $pending = SupplierBillLine::query()
            ->where('variant_id', $variantId)
            ->whereNull('supplier_id')
            ->whereNull('stock_lot_id')
            ->where('qty', '>', 0)
            ->lockForUpdate()
            ->orderBy('billed_on')
            ->orderBy('id')
            ->get();

        foreach ($pending as $line) {
            foreach ($this->openLots($variantId) as $lot) {
                $take = min($line->qty, $lot->qty_remaining);
                $lot->decrement('qty_remaining', $take);

                $unitCost = $lot->supplier_id === null ? null : $lot->unit_cost; // lot tunai: tanpa tagihan

                if ($take === $line->qty) {
                    $line->update(['supplier_id' => $lot->supplier_id, 'stock_lot_id' => $lot->id, 'unit_cost' => $unitCost]);

                    continue 2;
                }

                SupplierBillLine::create([
                    ...$line->only(['sales_order_item_id', 'variant_id', 'billed_on', 'is_consignment']),
                    'supplier_id' => $lot->supplier_id,
                    'stock_lot_id' => $lot->id,
                    'unit_cost' => $unitCost,
                    'qty' => $take,
                ]);
                $line->update(['qty' => $line->qty - $take]);
            }

            return; // lot habis: sisa tetap menunggu
        }
    }

    /**
     * Harga grosir bordir untuk total qty SKU dalam 1 pesanan: supplier = lot FIFO berikutnya (atau lot terakhir),
     * tingkat harga = min. qty tertinggi yang terpenuhi, berlaku pada tanggal pesanan.
     *
     * @return array{supplier_id: int, unit_price: float, min_qty: int}|null
     */
    public function wholesaleFor(ProductVariant $variant, int $qty, CarbonInterface|string $date): ?array
    {
        $date = Carbon::parse($date)->toDateString();
        $supplierId = StockLot::query()->where('variant_id', $variant->id)->where('qty_remaining', '>', 0)->whereNotNull('supplier_id')->orderBy('received_at')->orderBy('id')->value('supplier_id')
            ?? StockLot::query()->where('variant_id', $variant->id)->whereNotNull('supplier_id')->orderByDesc('received_at')->orderByDesc('id')->value('supplier_id')
            ?? $variant->model?->supplier_id; // mode barang keluar: tanpa lot, pakai supplier Model Produk

        $price = SupplierWholesalePrice::query()
            ->where('model_id', $variant->model_id)
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->where('min_qty', '<=', $qty)
            ->whereDate('effective_from', '<=', $date)
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

    /**
     * Hitung ulang semua lot & tagihan dari awal (urut waktu): stok sebelum $from ikut dipakai FIFO,
     * tetapi hanya pesanan mulai $from yang ditagihkan. $mode (opsional) mengganti mode tagihan sebelum dihitung;
     * di mode barang keluar tidak ada lot: tiap pesanan ditagihkan ke supplier Model Produk.
     * "unallocated" = pcs tanpa supplier (mode stok: stok minus; mode barang keluar: model belum diisi supplier).
     *
     * @return array{lots: int, lines: int, unallocated: int, no_price: int}
     */
    public function rebuild(?string $from = null, ?string $mode = null): array
    {
        $from = Carbon::parse($from ?? $this->startDate())->toDateString();
        Setting::updateOrCreate(['key' => 'supplier_billing_start'], ['value' => $from]);

        if ($mode !== null) {
            Setting::updateOrCreate(['key' => 'supplier_billing_mode'], ['value' => $mode]);
        }

        return DB::transaction(function (): array {
            SupplierBillLine::query()->delete();
            StockLot::query()->delete();

            $events = collect();

            GoodsReceiptItem::query()->with('receipt')
                ->whereHas('receipt', fn ($query) => $query->where('source', GoodsReceiptSource::BeliJadi))
                ->whereNotNull('unit_cost')
                ->get()
                ->each(fn (GoodsReceiptItem $item) => $events->push([$item->receipt->received_at->toDateTimeString(), 0, $item->id, fn () => $this->receive($item)]));

            SalesOrderItem::query()->with('salesOrder')
                ->whereHas('salesOrder', fn ($query) => $query->whereIn('status', [SalesOrderStatus::Completed, SalesOrderStatus::Returned]))
                ->whereHas('variant.model', fn ($query) => $query->where('kind', 'beli_jadi'))
                ->get()
                ->each(fn (SalesOrderItem $item) => $events->push([$item->salesOrder->order_date->endOfDay()->toDateTimeString(), 1, $item->id, fn () => $this->bill($item)]));

            SalesReturn::query()->where('restock', true)
                ->whereHas('salesOrderItem.variant.model', fn ($query) => $query->where('kind', 'beli_jadi'))
                ->get()
                ->each(fn (SalesReturn $return) => $events->push([Carbon::parse($return->returned_at)->endOfDay()->toDateTimeString(), 2, $return->id, fn () => $this->returned($return)]));

            $events->sortBy([[0, 'asc'], [1, 'asc'], [2, 'asc']])->each(fn (array $event) => $event[3]());

            return [
                'lots' => StockLot::query()->count(),
                'lines' => SupplierBillLine::query()->count(),
                'unallocated' => (int) SupplierBillLine::query()->whereNull('supplier_id')->whereNull('stock_lot_id')->sum('qty'),
                // Sudah ada supplier tapi harga beli belum ada pada tanggal pesanan (nilai tagihan kosong).
                'no_price' => (int) SupplierBillLine::query()->whereNotNull('supplier_id')->whereNull('unit_cost')->sum('qty'),
            ];
        });
    }

    /**
     * @return Collection<int, StockLot>
     */
    private function openLots(int $variantId): Collection
    {
        return StockLot::query()
            ->where('variant_id', $variantId)
            ->where('qty_remaining', '>', 0)
            ->lockForUpdate()
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    private function line(SalesOrderItem $item, CarbonInterface $date, int $qty, ?int $supplierId, ?float $unitCost, ?int $lotId): void
    {
        SupplierBillLine::create([
            'sales_order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'supplier_id' => $supplierId,
            'stock_lot_id' => $lotId,
            'billed_on' => $date,
            'qty' => $qty,
            'unit_cost' => $supplierId === null ? null : $unitCost, // tanpa supplier = belum teralokasi / lot tunai
            'is_consignment' => $item->is_consignment,
        ]);
    }
}
