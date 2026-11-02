<?php

use App\Enums\ReturnReason;
use App\Exceptions\BusinessRuleException;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\StoreAdSpend;
use App\Services\BalanceSheetService;
use App\Services\CostingService;
use App\Services\SalesOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('accounts for a partial return on its return date', function () {
    $f = businessFixture();
    $order = fixtureOrder($f, 10, '2026-10-01');
    app(SalesOrderService::class)->registerReturn($order->items()->first(), ['returned_at' => '2026-10-10', 'qty' => 2, 'restock' => true, 'credit_reseller' => true, 'reason' => ReturnReason::Lainnya]);
    $store = DB::table('v_store_monthly_pl')->where('store_id', $f['store']->id)->first();
    expect((float) $store->revenue)->toBe(1200.0);
    expect((float) $store->cost_of_goods)->toBe(800.0);
    expect((float) $store->net_profit)->toBe(400.0);
    $company = DB::table('v_konveksi_monthly_pl')->first();
    expect((float) $company->net_sales)->toBe(800.0);
    expect((float) $company->hpp)->toBe(480.0);
    expect((float) $company->net_profit)->toBe(320.0);
});

it('preserves the original sales month and does not reverse cost for a damaged unstocked return', function () {
    $f = businessFixture();
    $order = fixtureOrder($f, 10, '2026-10-01');
    app(SalesOrderService::class)->registerReturn($order->items()->first(), ['returned_at' => '2026-11-01', 'qty' => 10, 'restock' => false, 'credit_reseller' => true, 'reason' => ReturnReason::CacatBahan]);
    $store = DB::table('v_store_monthly_pl')->orderBy('month')->get();
    expect((float) $store[0]->revenue)->toBe(1500.0);
    expect((float) $store[1]->revenue)->toBe(-1500.0);
    $company = DB::table('v_konveksi_monthly_pl')->orderBy('month')->get();
    expect((float) $company[0]->hpp)->toBe(600.0);
    expect((float) $company[1]->hpp)->toBe(0.0);
    expect((float) $company->sum('net_profit'))->toBe(-600.0);
});

it('includes advertising expenses in a month without any sales', function () {
    $f = businessFixture();
    StoreAdSpend::create(['store_id' => $f['store']->id, 'spend_date' => '2026-12-01', 'amount' => 100]);
    $store = DB::table('v_store_monthly_pl')->where('store_id', $f['store']->id)->first();
    expect((float) $store->ads)->toBe(100.0);
    expect((float) $store->net_profit)->toBe(-100.0);
});

it('excludes later transactions and future opening balances from a historical balance sheet', function () {
    $f = businessFixture();
    fixtureOrder($f, 10, '2026-10-01');
    fixtureOrder($f, 5, '2026-10-20');
    $f['reseller']->update(['opening_balance' => 500, 'opening_balance_date' => '2026-10-20']);
    StoreAdSpend::create(['store_id' => $f['store']->id, 'spend_date' => '2026-10-21', 'amount' => 200]);
    $sheets = app(BalanceSheetService::class)->build(Carbon::parse('2026-10-10'));
    $store = $sheets['store:'.$f['store']->id];
    expect($store['ytd_profit'])->toBe(500.0);
    expect(BalanceSheetService::sumRoles($store['assets'], ['cash']))->toBe(1500.0);
    expect(BalanceSheetService::sumRoles($sheets['konveksi']['assets'], ['ar_reseller']))->toBe(1000.0);
});

it('uses the earliest known material price before it was recorded, and refuses materials without any price', function () {
    $f = businessFixture();
    expect(app(CostingService::class)->latestUnitCost($f['material'], '2025-12-31'))->toEqual('60.0000');

    $type = MaterialType::create(['code' => 'NOPRICE', 'name' => 'Tanpa harga', 'kind' => 'kain']);
    $unpriced = Material::create(['material_type_id' => $type->id, 'code' => 'NOPRICE', 'base_uom' => 'cm', 'qty_per_pack' => 1, 'is_active' => true]);
    expect(fn () => app(CostingService::class)->latestUnitCost($unpriced, '2026-10-31'))
        ->toThrow(BusinessRuleException::class, 'Harga bahan');
});

it('uses an actual buyer refund when it differs from the proportional estimate', function () {
    $f = businessFixture();
    $order = fixtureOrder($f, 10);
    app(SalesOrderService::class)->registerReturn($order->items()->first(), ['returned_at' => '2026-10-10', 'qty' => 2, 'restock' => true, 'credit_reseller' => true, 'reason' => ReturnReason::Lainnya, 'refund_amount' => 250]);
    $report = DB::table('v_store_monthly_pl')->first();
    expect((float) $report->revenue)->toBe(1250.0);
    expect((float) $report->net_profit)->toBe(450.0);
});
