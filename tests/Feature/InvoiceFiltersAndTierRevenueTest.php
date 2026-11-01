<?php

use App\Enums\ResellerInvoiceStatus;
use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\ResellerInvoices\Pages\ListResellerInvoices;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\OwnerTierHistory;
use App\Models\PriceTier;
use App\Models\ResellerInvoice;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo('2026-11-15');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
});

it('resolves quick period presets relative to today', function () {
    expect(DateRangeFilter::range('yesterday'))->toBe(['2026-11-14', '2026-11-14'])
        ->and(DateRangeFilter::range('last_7_days'))->toBe(['2026-11-09', '2026-11-15'])
        ->and(DateRangeFilter::range('last_month'))->toBe(['2026-10-16', '2026-11-15'])
        ->and(DateRangeFilter::range('last_3_months'))->toBe(['2026-08-16', '2026-11-15'])
        ->and(DateRangeFilter::range('previous_month'))->toBe(['2026-10-01', '2026-10-31'])
        ->and(DateRangeFilter::resolve(['preset' => 'custom', 'from' => '2026-01-05', 'until' => null]))->toBe(['2026-01-05', null]);
});

it('filters reseller invoices by quick period, date basis and overdue status', function () {
    $f = businessFixture();
    $invoice = fn (string $no, string $start, string $end, array $extra = []): ResellerInvoice => ResellerInvoice::create([
        'reseller_id' => $f['reseller']->id, 'invoice_no' => $no, 'period_start' => $start, 'period_end' => $end, ...$extra,
    ]);
    $yesterday = $invoice('INV-KEMARIN', '2026-11-14', '2026-11-14');
    $week = $invoice('INV-MINGGU', '2026-11-03', '2026-11-09', ['status' => ResellerInvoiceStatus::Issued, 'issued_at' => '2026-11-10', 'due_date' => '2026-11-12']);
    $old = $invoice('INV-JULI', '2026-07-01', '2026-07-31', ['status' => ResellerInvoiceStatus::Issued, 'issued_at' => '2026-11-14', 'due_date' => '2026-12-01']);

    Livewire::test(ListResellerInvoices::class)
        ->filterTable('period', ['date_field' => 'period', 'preset' => 'yesterday'])
        ->assertCanSeeTableRecords([$yesterday])
        ->assertCanNotSeeTableRecords([$week, $old])
        ->filterTable('period', ['date_field' => 'period', 'preset' => 'last_7_days'])
        ->assertCanSeeTableRecords([$yesterday, $week])
        ->assertCanNotSeeTableRecords([$old])
        ->filterTable('period', ['date_field' => 'issued_at', 'preset' => 'yesterday'])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$yesterday, $week])
        ->filterTable('period', ['date_field' => 'period', 'preset' => 'custom', 'from' => '2026-07-10', 'until' => '2026-07-20'])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$yesterday, $week])
        ->resetTableFilters()
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$week])
        ->assertCanNotSeeTableRecords([$yesterday, $old]);
});

it('shows units sold, tier revenue and konveksi revenue per price tier', function () {
    Setting::updateOrCreate(['key' => 'tier_price_rounding'], ['value' => 1]);
    $f = businessFixture();
    $base = PriceTier::create(['code' => 'T1', 'name' => 'Tier 1', 'adjustment_pct' => 0]);
    $premium = PriceTier::create(['code' => 'T2', 'name' => 'Tier 2', 'adjustment_pct' => 0.10]);
    OwnerTierHistory::create(['owner_id' => $f['owner']->id, 'price_tier_id' => $base->id, 'effective_from' => '2026-01-01']);
    fixtureOrder($f, 4, '2026-09-10');
    OwnerTierHistory::create(['owner_id' => $f['owner']->id, 'price_tier_id' => $premium->id, 'effective_from' => '2026-10-01']);
    fixtureOrder($f, 10, '2026-11-14');

    Livewire::test(ManagePriceTiers::class)
        ->assertTableColumnStateSet('qty_sold', 4, $base)
        ->assertTableColumnStateSet('konveksi_amount', 400, $base)
        ->assertTableColumnStateSet('tier_amount', 0, $base)
        ->assertTableColumnStateSet('owners_now', 0, $base)
        ->assertTableColumnStateSet('qty_sold', 10, $premium)
        ->assertTableColumnStateSet('base_amount', 1000, $premium)
        ->assertTableColumnStateSet('tier_amount', 100, $premium)
        ->assertTableColumnStateSet('tier_per_pcs', 10, $premium)
        ->assertTableColumnStateSet('konveksi_amount', 1100, $premium)
        ->assertTableColumnStateSet('owners_now', 1, $premium)
        ->filterTable('period', ['preset' => 'yesterday'])
        ->assertTableColumnStateSet('qty_sold', 0, $base)
        ->assertTableColumnStateSet('qty_sold', 10, $premium);
});
