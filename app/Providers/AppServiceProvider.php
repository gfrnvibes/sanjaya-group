<?php

namespace App\Providers;

use App\Models\GoodsReceiptItem;
use App\Models\MaterialPurchaseItem;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\StockAdjustmentItem;
use App\Models\StockOpnameItem;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alias pendek & stabil untuk kolom source_type di ledger stok/bahan.
        Relation::morphMap([
            'goods_receipt_item' => GoodsReceiptItem::class,
            'material_purchase_item' => MaterialPurchaseItem::class,
            'sales_order_item' => SalesOrderItem::class,
            'sales_return' => SalesReturn::class,
            'stock_adjustment_item' => StockAdjustmentItem::class,
            'stock_opname_item' => StockOpnameItem::class,
            'work_order_item' => WorkOrderItem::class,
        ]);
    }
}
