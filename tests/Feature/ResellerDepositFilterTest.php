<?php

use App\Filament\Resources\ResellerReceivables\Pages\ManageResellerReceivables;
use App\Filament\Resources\ResellerReceivables\ResellerReceivableResource;
use App\Models\CashAccount;
use App\Models\Reseller;
use App\Models\ResellerPayment;
use App\Models\ResellerReceivable;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('filters reseller balances into outstanding, deposit and settled', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);

    $f = businessFixture();
    fixtureOrder($f, 10, '2026-10-01'); // tagihan 1.000 → piutang
    $deposit = Reseller::create(['owner_id' => $f['owner']->id, 'name' => 'Reseller Deposit', 'opening_balance' => 0, 'is_active' => true]);
    ResellerPayment::create(['reseller_id' => $deposit->id, 'paid_at' => '2026-10-02', 'amount' => 500, 'method' => 'transfer', 'cash_account_id' => CashAccount::create(['name' => 'BCA'])->id]);
    $settled = Reseller::create(['owner_id' => $f['owner']->id, 'name' => 'Reseller Lunas', 'opening_balance' => 0, 'is_active' => true]);

    [$owing, $depositRow, $settledRow] = [ResellerReceivable::find($f['reseller']->id), ResellerReceivable::find($deposit->id), ResellerReceivable::find($settled->id)];

    Livewire::test(ManageResellerReceivables::class)
        ->assertCanSeeTableRecords([$owing])
        ->assertCanNotSeeTableRecords([$depositRow, $settledRow])
        ->filterTable('balance', ResellerReceivableResource::BALANCE_DEPOSIT)
        ->assertCanSeeTableRecords([$depositRow])
        ->assertCanNotSeeTableRecords([$owing, $settledRow])
        ->assertSee('Deposit')
        ->filterTable('balance', ResellerReceivableResource::BALANCE_SETTLED)
        ->assertCanSeeTableRecords([$settledRow])
        ->assertCanNotSeeTableRecords([$owing, $depositRow])
        ->filterTable('balance', null)
        ->assertCanSeeTableRecords([$owing, $depositRow, $settledRow]);
});
