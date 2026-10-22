<?php

namespace App\Imports;

use App\Enums\ReturnReason;
use App\Exceptions\BusinessRuleException;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\SalesOrderService;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Retur penjualan per baris pesanan (lewat SalesOrderService::registerReturn: stok kembali & tagihan dikurangi).
 * Baris yang sama persis (pesanan, SKU, tanggal, qty) dengan retur yang sudah ada dilewati.
 */
class SalesReturnImport extends RowImporter
{
    public function __construct(private SalesOrderService $orders) {}

    public function title(): string
    {
        return 'Retur Penjualan';
    }

    public function permission(): string
    {
        return 'kelola penjualan';
    }

    public function columns(): array
    {
        return [
            'TANGGAL RETUR' => ['returned_at', 'Ya', 'Tanggal barang retur diterima.'],
            'NAMA TOKO' => ['store', 'Ya', 'Toko asal pesanan.'],
            'NO PESANAN' => ['order_no', 'Ya', 'No. pesanan di sistem (lihat menu Pesanan / export pesanan).'],
            'SKU VARIAN' => ['sku', 'Ya', 'SKU varian atau SKU BigSeller pada pesanan tersebut.'],
            'QTY' => ['qty', 'Ya', 'Maksimal sisa qty yang belum diretur.'],
            'NILAI REFUND' => ['refund_amount', '-', 'Refund ke pembeli. Kosong = proporsional pendapatan pesanan.'],
            'ALASAN' => ['reason', '-', 'Cacat jahitan / Cacat bahan / Ukuran tidak sesuai / Salah kirim / Tidak sesuai foto / Rusak saat pengiriman / Pembeli berubah pikiran / Lainnya. Kosong = Lainnya.'],
            'MASUK STOK' => ['restock', '-', 'Ya / Tidak. Default Ya (barang kembali ke stok).'],
            'GUDANG' => ['warehouse', '-', 'Gudang tujuan bila masuk stok. Kosong = gudang asal pesanan.'],
            'POTONG TAGIHAN' => ['credit_reseller', '-', 'Ya / Tidak. Default Ya (mengurangi tagihan reseller).'],
            'CATATAN' => ['note', '-', ''],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['returned_at', 'store', 'order_no', 'sku', 'qty'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['store' => 'NAMA TOKO', 'order_no' => 'NO PESANAN', 'sku' => 'SKU VARIAN']);
        $date = $row->date('returned_at', 'TANGGAL RETUR', required: true);
        $qty = $row->number('qty', 'QTY', min: 1, required: true, integer: true);
        $refund = $row->number('refund_amount', 'NILAI REFUND', min: 0);
        $reason = $row->enum('reason', ReturnReason::class, 'ALASAN', [
            'cacat jahit' => ReturnReason::CacatJahit, 'jahitan' => ReturnReason::CacatJahit,
            'cacat' => ReturnReason::CacatBahan, 'bahan' => ReturnReason::CacatBahan,
            'ukuran' => ReturnReason::SalahUkuran, 'salah ukuran' => ReturnReason::SalahUkuran,
            'salah kirim' => ReturnReason::SalahKirim, 'tidak sesuai' => ReturnReason::TidakSesuai,
            'rusak' => ReturnReason::RusakKirim, 'berubah pikiran' => ReturnReason::BerubahPikiran,
        ]) ?? ReturnReason::Lainnya;
        $restock = $row->bool('restock', 'MASUK STOK') ?? true;
        $credit = $row->bool('credit_reseller', 'POTONG TAGIHAN') ?? true;
        $row->maxLength(['note' => ['CATATAN', 255]]);
        $row->throwIfInvalid();

        $store = $this->store($row->text('store'));
        $order = $this->findCi(SalesOrder::query()->where('store_id', $store->id), 'order_no', $row->text('order_no'))
            ?? throw new BusinessRuleException("pesanan {$row->text('order_no')} di toko {$store->name} tidak ditemukan");
        $variant = $this->variant($row->text('sku'));
        $item = $order->items()->where('variant_id', $variant->id)->first()
            ?? throw new BusinessRuleException("SKU {$variant->sku} tidak ada di pesanan {$order->order_no}");

        if ($this->alreadyExists("{$item->id}|{$date}|{$qty}", fn (): int => SalesReturn::query()
            ->where('sales_order_item_id', $item->id)->whereDate('returned_at', $date)->where('qty', (int) $qty)->count())) {
            $this->bump('skipped');

            return;
        }

        $this->orders->registerReturn($item, [
            'returned_at' => $date,
            'qty' => (int) $qty,
            'reason' => $reason,
            'refund_amount' => $refund,
            'restock' => $restock,
            'warehouse_id' => $restock && $row->filled('warehouse') ? $this->warehouse($row->text('warehouse'))->id : null,
            'credit_reseller' => $credit,
            'note' => $row->text('note') ?: null,
        ]);
        $this->bump('created');
    }
}
