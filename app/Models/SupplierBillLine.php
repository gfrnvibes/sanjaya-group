<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris tagihan supplier beli jadi:
 *  - kind "pembelian": Barang Masuk Beli Jadi ber-supplier (stok masuk konveksi), dari goods_receipt_item;
 *  - kind "barang_keluar": qty pesanan Selesai yang dikirim langsung supplier karena stok konveksi tidak cukup.
 * supplier_id NULL = Model Produk belum punya supplier. is_consignment = titipan grosir bordir (harga grosir).
 */
#[Fillable(['kind', 'sales_order_item_id', 'goods_receipt_item_id', 'variant_id', 'supplier_id', 'stock_lot_id', 'sales_return_id', 'billed_on', 'qty', 'unit_cost', 'is_consignment'])]
class SupplierBillLine extends Model
{
    public const KIND_PURCHASE = 'pembelian';

    public const KIND_GOODS_OUT = 'barang_keluar';

    public const KINDS = [
        self::KIND_PURCHASE => 'Tagihan Pembelian',
        self::KIND_GOODS_OUT => 'Tagihan Barang Keluar',
    ];

    protected $attributes = [
        'kind' => self::KIND_GOODS_OUT,
    ];

    protected function casts(): array
    {
        return [
            'billed_on' => 'date',
            'qty' => 'integer',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_consignment' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /** @return BelongsTo<GoodsReceiptItem, $this> */
    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }
}
