<?php

namespace App\Providers\Filament;

use App\Filament\Pages\SalesDashboard;
use App\Http\Controllers\DownloadPaymentProof;
use App\Http\Controllers\DownloadSalesImportFailures;
use App\Http\Controllers\ShowPrintedTable;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdministratorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('administrator')
            ->path('administrator')
            ->login()
            ->databaseTransactions()
            ->brandName('Sanjaya Konveksi')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications() // lonceng: baris import yang perlu dicek
            ->databaseNotificationsPolling('60s')
            ->navigationGroups([
                NavigationGroup::make('Penjualan'),
                NavigationGroup::make('Keuangan'),
                NavigationGroup::make('Produksi'),
                NavigationGroup::make('Stok'),
                NavigationGroup::make('Produk'),
                NavigationGroup::make('Bahan & HPP'),
                NavigationGroup::make('Reseller & Toko'),
                NavigationGroup::make('Laporan'),
                NavigationGroup::make('Pengaturan')->collapsed(),
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('payment-proofs/{payment}', DownloadPaymentProof::class)->name('payment-proofs.download');
                Route::get('import-gagal/unduh', DownloadSalesImportFailures::class)->name('sales-import-failures.download');
                Route::get('cetak/{token}', ShowPrintedTable::class)->whereUuid('token')->name('print');
            })
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): View => view('filament.pages.sales-dashboard-styles'),
                scopes: SalesDashboard::class,
            )
            ->maxContentWidth(Width::SevenExtraLarge);
    }
}
