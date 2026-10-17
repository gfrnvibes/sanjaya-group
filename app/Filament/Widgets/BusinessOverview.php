<?php

namespace App\Filament\Widgets;

use App\Enums\SalesOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Models\KonveksiMonthlyProfit;
use App\Models\ResellerReceivable;
use App\Models\SalesOrderItem;
use App\Models\WorkOrder;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class BusinessOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $month = today()->startOfMonth();

        $billedThisMonth = (float) SalesOrderItem::query()
            ->whereHas('salesOrder', fn ($query) => $query
                ->where('status', '!=', SalesOrderStatus::Cancelled)
                ->where('order_date', '>=', $month))
            ->sum('bill_amount');

        $qtyThisMonth = (int) SalesOrderItem::query()
            ->whereHas('salesOrder', fn ($query) => $query
                ->whereNotIn('status', [SalesOrderStatus::Cancelled, SalesOrderStatus::Returned])
                ->where('order_date', '>=', $month))
            ->sum('qty');

        $netProfit = (float) (KonveksiMonthlyProfit::query()->whereDate('month', $month)->value('net_profit') ?? 0);

        $receivable = (float) ResellerReceivable::query()->where('outstanding', '>', 0)->sum('outstanding');

        $lowStock = (int) DB::scalar(<<<'SQL'
            SELECT COUNT(*) FROM product_variants v
            WHERE v.is_active AND v.deleted_at IS NULL
              AND COALESCE((SELECT SUM(qty) FROM stock_balances b WHERE b.variant_id = v.id), 0) <= v.min_stock
            SQL);

        $openWorkOrders = WorkOrder::query()->whereIn('status', [WorkOrderStatus::Cutting, WorkOrderStatus::Sewing])->count();

        return [
            Stat::make('Tagihan ke Reseller Bulan Ini', Number::currency($billedThisMonth, 'IDR', 'id', 0))
                ->description(Number::format($qtyThisMonth, locale: 'id').' pcs terjual')
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->color('primary'),
            Stat::make('Laba Bersih Konveksi Bulan Ini', Number::currency($netProfit, 'IDR', 'id', 0))
                ->descriptionIcon(Heroicon::OutlinedPresentationChartLine)
                ->description('Setelah HPP & biaya operasional')
                ->color($netProfit >= 0 ? 'success' : 'danger'),
            Stat::make('Total Piutang Reseller', Number::currency($receivable, 'IDR', 'id', 0))
                ->descriptionIcon(Heroicon::OutlinedScale)
                ->description('Belum dibayar')
                ->color($receivable > 0 ? 'warning' : 'success'),
            Stat::make('Varian Stok Menipis', (string) $lowStock)
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->description('Stok <= minimum')
                ->color($lowStock > 0 ? 'danger' : 'success'),
            Stat::make('SPK Berjalan', (string) $openWorkOrders)
                ->descriptionIcon(Heroicon::OutlinedScissors)
                ->description('Dipotong / dijahit')
                ->color('info'),
        ];
    }
}
