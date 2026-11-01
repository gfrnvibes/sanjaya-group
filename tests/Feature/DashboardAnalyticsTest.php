<?php

use App\Filament\Pages\BalanceSheet;
use App\Filament\Widgets\Dashboard\ActivePartnersChart;
use App\Filament\Widgets\Dashboard\CashFlowChart;
use App\Filament\Widgets\Dashboard\ChannelGrowthChart;
use App\Filament\Widgets\Dashboard\ChannelShareChart;
use App\Filament\Widgets\Dashboard\FinancialPosition;
use App\Filament\Widgets\Dashboard\LowStockTable;
use App\Filament\Widgets\Dashboard\ProductGrowthChart;
use App\Filament\Widgets\Dashboard\ResellerGrowthChart;
use App\Filament\Widgets\Dashboard\SalesTrendChart;
use App\Filament\Widgets\Dashboard\StoreGrowthChart;
use App\Filament\Widgets\Dashboard\TopReceivables;
use App\Models\Store;
use App\Models\User;
use App\Services\BalanceSheetService;
use App\Services\DashboardAnalytics;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo('2026-11-15');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
});

it('compares the selected period with the same span of the previous period', function () {
    $f = businessFixture();
    fixtureOrder($f, 10, '2026-10-10');
    fixtureOrder($f, 4, '2026-10-20'); // di luar 1–15 Okt: tidak ikut pembanding
    fixtureOrder($f, 15, '2026-11-05');

    $analytics = app(DashboardAnalytics::class);
    $period = $analytics->period('month');

    expect($period['from']->toDateString())->toBe('2026-11-01')
        ->and($period['previous_from']->toDateString())->toBe('2026-10-01')
        ->and($period['previous_until']->toDateString())->toBe('2026-10-15');

    expect($analytics->compareByDimension('reseller', $period, 10))->toBe([['name' => 'Reseller', 'current' => 1500.0, 'previous' => 1000.0]])
        ->and($analytics->compareByDimension('model', $period, 10, 'qty'))->toBe([['name' => 'Model', 'current' => 15.0, 'previous' => 10.0]]);
});

it('builds monthly series per store and counts active and new stores', function () {
    $f = businessFixture();
    fixtureOrder($f, 10, '2026-10-10');
    $second = Store::create(['reseller_id' => $f['reseller']->id, 'channel_id' => $f['channel']->id, 'name' => 'Toko Kedua', 'is_active' => true]);
    fixtureOrder([...$f, 'store' => $second], 3, '2026-11-02');

    $analytics = app(DashboardAnalytics::class);
    $months = $analytics->months(2);

    expect($analytics->monthLabels($months))->toBe(['Okt 26', 'Nov 26'])
        ->and($analytics->monthlyByDimension('store', $months, 1))->toBe(['Shop' => [1000.0, 0.0], 'Lainnya' => [0.0, 300.0]])
        ->and($analytics->monthlyByDimension('channel', $months, 5))->toBe(['Shop' => [1000.0, 300.0]])
        ->and($analytics->activePerMonth($months))->toBe([
            'stores' => [1, 1],
            'resellers' => [1, 1],
            'variants' => [1, 1],
            'new_stores' => [1, 1],
        ]);
});

it('renders the main dashboard with charts, balance summary and tables', function () {
    $f = businessFixture();
    fixtureOrder($f, 10, '2026-10-10');

    $this->actingAs($this->admin)->get('/administrator')->assertOk();

    foreach ([SalesTrendChart::class, CashFlowChart::class, ActivePartnersChart::class, ChannelGrowthChart::class, ChannelShareChart::class,
        StoreGrowthChart::class, ResellerGrowthChart::class, ProductGrowthChart::class] as $chart) {
        Livewire::test($chart)->assertOk()->set('filter', array_key_last((new ReflectionMethod($chart, 'getFilters'))->invoke(new $chart)))->assertOk();
    }

    Livewire::test(FinancialPosition::class)->assertOk()->assertSee('Piutang Reseller')->assertSee('1.000');
    Livewire::test(TopReceivables::class)->assertOk()->assertSee('Reseller');
    $f['variant']->update(['min_stock' => 200]);
    Livewire::test(LowStockTable::class)->assertOk()->assertSee('001234');
});

it('shows the balance sheet page with a filterable, paginated summary of all sources', function () {
    $f = businessFixture();
    fixtureOrder($f, 10, '2026-10-10');
    $storeKey = 'store:'.$f['store']->id;

    $this->actingAs($this->admin)->get('/administrator/neraca')->assertOk()->assertSee('Neraca Keuangan');

    $page = Livewire::test(BalanceSheet::class)
        ->assertSee('Ringkasan Semua Sumber')
        ->assertCanSeeTableRecords([BalanceSheetService::KONVEKSI, BalanceSheetService::ALL_STORES, $storeKey])
        ->filterTable('kind', BalanceSheet::KIND_STORE)
        ->assertCanSeeTableRecords([$storeKey])
        ->assertCanNotSeeTableRecords([BalanceSheetService::KONVEKSI])
        ->resetTableFilters()
        ->filterTable('channel', $f['channel']->id)
        ->assertCanSeeTableRecords([$storeKey])
        ->assertCanNotSeeTableRecords([BalanceSheetService::ALL_STORES])
        ->resetTableFilters()
        ->searchTable('Konveksi')
        ->assertCanSeeTableRecords([BalanceSheetService::KONVEKSI])
        ->assertCanNotSeeTableRecords([$storeKey]);

    $page->searchTable(null)
        ->callAction(TestAction::make('show')->table($storeKey))
        ->assertSet('source', $storeKey)
        ->assertSee('Utang ke Konveksi')
        ->set('period', '2026-09')
        ->assertOk();
});

it('limits the balance sheet page to staff with its Shield permission', function () {
    $staff = User::factory()->create(['is_active' => true]);
    $this->actingAs($staff)->get('/administrator/neraca')->assertForbidden();

    $staff->givePermissionTo(Permission::findOrCreate('View:BalanceSheet', 'web'));
    $staff->unsetRelation('permissions');
    $this->get('/administrator/neraca')->assertOk();
});
