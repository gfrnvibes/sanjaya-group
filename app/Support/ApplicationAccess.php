<?php

namespace App\Support;

use App\Imports\ImportRegistry;
use App\Models\ReadOnlyModel;
use App\Models\SalesImportFailure;
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
        // Import Perlu Dicek: semua kategori data; tiap user hanya melihat kategori yang boleh ia impor.
        if (class_basename($model) === 'SalesImportFailure') {
            // Hapus permanen baris Import Perlu Dicek: khusus admin.
            if (in_array($ability, ['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'], true)) {
                return self::allows('administrator', $user);
            }

            $categories = collect(ImportRegistry::all());

            return $model instanceof SalesImportFailure
                ? isset($categories[$model->importer]) && self::allows($categories[$model->importer]['permission'], $user)
                : $categories->pluck('permission')->unique()->contains(fn (string $permission): bool => self::allows($permission, $user));
        }

        $permission = match (class_basename($model)) {
            'User', 'Role', 'Permission', 'Setting', 'Activity' => 'administrator',
            'SalesOrder', 'SalesOrderItem', 'SalesReturn', 'SalesImportBatch', 'SkuAlias' => 'kelola penjualan',
            'StockMovement', 'StockBalance', 'MaterialMovement', 'MaterialBalance', 'StockOpname', 'StockOpnameItem', 'StockAdjustment', 'StockAdjustmentItem', 'GoodsReceipt', 'GoodsReceiptItem', 'Warehouse', 'Supplier', 'MaterialPurchase', 'MaterialPurchaseItem' => 'kelola stok',
            'WorkOrder', 'WorkOrderItem', 'Tailor' => 'kelola produksi',
            'ResellerInvoice', 'ResellerPayment', 'TailorPayment', 'CashAccount', 'Expense', 'ExpenseCategory', 'StoreAdSpend', 'SupplierPayment', 'SupplierBillLine' => 'kelola keuangan',
            'Owner', 'OwnerTierHistory', 'Reseller', 'Store', 'Channel', 'ChannelFeeRate', 'PriceTier' => 'kelola mitra',
            'KonveksiMonthlyProfit', 'StoreMonthlyProfit', 'ResellerReceivable', 'SalesLine' => 'lihat laporan',
            'ProductModel', 'ProductVariant', 'ProductCategory', 'Color', 'Size', 'Material', 'MaterialType', 'LaborType', 'Bom', 'BomItem', 'VariantCost', 'ResellerPriceHistory', 'VariantPricing', 'SupplierWholesalePrice' => 'kelola katalog',
            'StockLot' => 'kelola stok',
            default => null,
        };

        if (is_a($model, ReadOnlyModel::class, true) && ! in_array($ability, ['view', 'viewAny'], true)) {
            return false;
        }

        return $permission !== null && self::allows($permission, $user);
    }
}
