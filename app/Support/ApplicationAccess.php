<?php

namespace App\Support;

use App\Models\ReadOnlyModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ApplicationAccess
{
    public const PERMISSIONS = ['kelola penjualan', 'kelola stok', 'kelola produksi', 'kelola keuangan', 'kelola katalog', 'kelola mitra', 'lihat laporan'];

    public static function allows(string $permission, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $user->is_active && $user->isKonveksiStaff()
            && ($user->hasRole('admin') || $user->getAllPermissions()->contains('name', $permission));
    }

    /** @param Model|class-string<Model> $model */
    public static function model(string $ability, Model|string $model, User $user): bool
    {
        $permission = match (class_basename($model)) {
            'User', 'Role', 'Permission', 'Setting' => 'administrator',
            'SalesOrder', 'SalesOrderItem', 'SalesReturn', 'SalesImportBatch', 'SalesImportFailure', 'SkuAlias' => 'kelola penjualan',
            'StockMovement', 'StockBalance', 'MaterialMovement', 'MaterialBalance', 'StockOpname', 'StockOpnameItem', 'StockAdjustment', 'StockAdjustmentItem', 'GoodsReceipt', 'GoodsReceiptItem', 'Warehouse', 'Supplier', 'MaterialPurchase', 'MaterialPurchaseItem' => 'kelola stok',
            'WorkOrder', 'WorkOrderItem', 'Tailor' => 'kelola produksi',
            'ResellerInvoice', 'ResellerPayment', 'TailorPayment', 'CashAccount', 'Expense', 'ExpenseCategory', 'StoreAdSpend' => 'kelola keuangan',
            'Owner', 'OwnerTierHistory', 'Reseller', 'Store', 'Channel', 'ChannelFeeRate', 'PriceTier' => 'kelola mitra',
            'KonveksiMonthlyProfit', 'StoreMonthlyProfit', 'ResellerReceivable', 'SalesLine' => 'lihat laporan',
            'ProductModel', 'ProductVariant', 'ProductCategory', 'Color', 'Size', 'Material', 'MaterialType', 'LaborType', 'Bom', 'BomItem', 'VariantCost', 'ResellerPriceHistory', 'VariantPricing' => 'kelola katalog',
            default => null,
        };

        if (is_a($model, ReadOnlyModel::class, true) && ! in_array($ability, ['view', 'viewAny'], true)) {
            return false;
        }

        return $permission !== null && self::allows($permission, $user);
    }
}
