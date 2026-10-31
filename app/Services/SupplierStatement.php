<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierBillLine;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan tagihan supplier per periode:
 * saldo awal (sebelum periode) + Tagihan Pembelian + Tagihan Barang Keluar (+ titipan grosir) - pembayaran
 * = sisa tagihan akhir periode (1 saldo per supplier).
 */
class SupplierStatement
{
    /**
     * Tambahkan kolom ringkasan ke query supplier.
     *
     * @param  Builder<Supplier>  $suppliers
     * @return Builder<Supplier>
     */
    public function withTotals(Builder $suppliers, ?string $from, ?string $until): Builder
    {
        $lines = fn (string $expression, string $where = 'TRUE'): \Illuminate\Database\Query\Builder => DB::table('supplier_bill_lines as l')
            ->selectRaw("COALESCE(SUM({$expression}), 0)")
            ->whereColumn('l.supplier_id', 'suppliers.id')
            ->whereRaw($where)
            ->when($from, fn ($query) => $query->whereDate('l.billed_on', '>=', $from))
            ->when($until, fn ($query) => $query->whereDate('l.billed_on', '<=', $until));
        $payments = fn (): \Illuminate\Database\Query\Builder => DB::table('supplier_payments as p')
            ->selectRaw('COALESCE(SUM(p.amount), 0)')
            ->whereColumn('p.supplier_id', 'suppliers.id')
            ->when($from, fn ($query) => $query->whereDate('p.paid_at', '>=', $from))
            ->when($until, fn ($query) => $query->whereDate('p.paid_at', '<=', $until));
        $opening = $from === null ? DB::raw('0') : DB::table('suppliers as s2')
            ->selectRaw('COALESCE((SELECT SUM(amount) FROM supplier_bill_lines WHERE supplier_id = s2.id AND billed_on < ?), 0)
                       - COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id = s2.id AND paid_at < ?), 0)', [$from, $from])
            ->whereColumn('s2.id', 'suppliers.id');

        return $suppliers
            ->select('suppliers.*')
            ->selectSub($opening, 'opening_balance')
            ->selectSub($lines('l.qty', "l.kind = 'pembelian'"), 'qty_purchased')
            ->selectSub($lines('l.amount', "l.kind = 'pembelian'"), 'billed_purchase')
            ->selectSub($lines('l.qty', "l.kind = 'barang_keluar'"), 'qty_sold')
            ->selectSub($lines('l.amount', "l.kind = 'barang_keluar' AND NOT l.is_consignment"), 'billed_goods_out')
            ->selectSub($lines('l.amount', 'NOT l.is_consignment'), 'billed_normal')
            ->selectSub($lines('l.amount', 'l.is_consignment'), 'billed_consignment')
            ->selectSub($payments(), 'paid');
    }

    /**
     * Sisa tagihan supplier (semua waktu, sampai tanggal opsional).
     */
    public function balance(?Supplier $supplier, ?string $until = null): float
    {
        if ($supplier === null) {
            return 0.0;
        }

        $billed = (float) SupplierBillLine::query()->where('supplier_id', $supplier->id)->when($until, fn ($query) => $query->whereDate('billed_on', '<=', $until))->sum('amount');
        $paid = (float) SupplierPayment::query()->where('supplier_id', $supplier->id)->when($until, fn ($query) => $query->whereDate('paid_at', '<=', $until))->sum('amount');

        return round($billed - $paid, 2);
    }

    /**
     * Qty pesanan yang belum teralokasi ke supplier (stok minus, menunggu barang masuk).
     *
     * @return array{qty: int, variants: int}
     */
    public function unallocated(?string $from = null, ?string $until = null): array
    {
        // Tagihan Barang Keluar yang Model Produk-nya belum punya supplier.
        $query = SupplierBillLine::query()->whereNull('supplier_id')
            ->when($from, fn ($query) => $query->whereDate('billed_on', '>=', $from))
            ->when($until, fn ($query) => $query->whereDate('billed_on', '<=', $until));

        return ['qty' => (int) $query->clone()->sum('qty'), 'variants' => $query->clone()->distinct()->count('variant_id')];
    }
}
