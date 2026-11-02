<?php

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\Accounting\GeneralLedgerPage;
use App\Filament\Pages\Accounting\LedgerClosingPage;
use App\Filament\Pages\Accounting\TrialBalance;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\MaterialOpname;
use App\Models\ResellerPayment;
use App\Models\StockOpname;
use App\Models\TailorPayment;
use App\Models\User;
use App\Services\BalanceSheetService;
use App\Services\FinancialStatements;
use App\Services\GeneralLedger;
use App\Services\InventoryService;
use App\Services\WorkOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo('2026-11-15');
    $this->f = businessFixture();
    $this->bank = CashAccount::create(['name' => 'BCA']);
    $this->ledger = app(GeneralLedger::class);
    $this->balance = fn (string $role, ?int $resellerId = null, string $until = '2026-11-15'): float => round((float) DB::table('journal_lines as l')
        ->join('accounts as a', 'a.id', '=', 'l.account_id')
        ->where('a.role', $role)->whereNull('l.store_id')->where('l.entry_date', '<=', $until)
        ->when($resellerId, fn ($query) => $query->where('l.reseller_id', $resellerId))
        ->sum(DB::raw('l.debit - l.credit')), 2);
});

it('posts balanced journals for sales, opening balances and payments, mirrored in the store books', function () {
    $this->f['reseller']->update(['opening_balance' => 500, 'opening_balance_date' => '2026-07-31']);
    fixtureOrder($this->f, 10, '2026-10-05'); // tagihan 1.000, HPP 600
    ResellerPayment::create(['reseller_id' => $this->f['reseller']->id, 'paid_at' => '2026-10-20', 'amount' => 700, 'method' => 'transfer', 'cash_account_id' => $this->bank->id]);

    $this->ledger->sync();

    expect(DB::table('journal_lines')->sum('debit'))->toEqual(DB::table('journal_lines')->sum('credit'))
        ->and(($this->balance)('ar_reseller', $this->f['reseller']->id))->toBe(800.0)
        ->and((float) DB::table('v_reseller_ar')->where('reseller_id', $this->f['reseller']->id)->value('outstanding'))->toBe(800.0)
        ->and(($this->balance)('inventory_fg'))->toBe(5400.0) // stok awal 6.000 - HPP 600
        ->and(($this->balance)('opening_equity'))->toBe(-(500.0 + 60000 + 6000));

    // buku toko: utang ke konveksi = piutang konveksi
    $payable = (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.role', 'store_payable')->sum(DB::raw('l.credit - l.debit'));
    expect($payable)->toBe(800.0);

    $trial = app(FinancialStatements::class)->trialBalance('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    $total = collect($trial)->firstWhere('key', 'total')['amounts'];
    expect($total[1])->toBe($total[2])->and($total[3])->toBe($total[4]);
});

it('records material purchases and combined wages paid by a reseller as a deduction of its receivable, not cash', function () {
    fixtureOrder($this->f, 50, '2026-10-05'); // piutang 5.000
    $purchasePayment = ResellerPayment::create(['reseller_id' => $this->f['reseller']->id, 'paid_at' => '2026-10-31', 'amount' => 1800, 'method' => PaymentMethod::CostOffset, 'note' => 'Belanja semi wool']);
    $wagePayment = ResellerPayment::create(['reseller_id' => $this->f['reseller']->id, 'paid_at' => '2026-10-31', 'amount' => 2800, 'method' => PaymentMethod::CostOffset, 'note' => 'Upah mingguan']);

    app(InventoryService::class)->createMaterialPurchase(
        ['warehouse_id' => $this->f['materialWarehouse']->id, 'purchase_date' => '2026-10-31', 'payment_method' => 'reseller', 'reseller_payment_id' => $purchasePayment->id],
        [['material_id' => $this->f['material']->id, 'qty_pack' => 1, 'qty_base' => 9144, 'total_price' => 1800]],
    );
    TailorPayment::create(['tailor_id' => null, 'paid_at' => '2026-10-31', 'amount' => 2800, 'reseller_payment_id' => $wagePayment->id, 'note' => 'Upah gabungan']);

    $this->ledger->sync();
    $sheet = app(BalanceSheetService::class)->konveksiSheet(Carbon::parse('2026-10-31'));

    expect(($this->balance)('ar_reseller', $this->f['reseller']->id))->toBe(5000.0 - 4600)
        ->and(($this->balance)('offset_clearing'))->toBe(0.0)
        ->and(($this->balance)('inventory_material'))->toBe(60000.0 + 1800)
        ->and(($this->balance)('wages_payable'))->toBe(2800.0) // dibayar di muka: uang muka upah
        ->and(BalanceSheetService::sumRoles($sheet['assets'], ['cash']))->toBe(0.0)
        ->and(BalanceSheetService::total($sheet['assets']))->toBe(round(BalanceSheetService::total($sheet['liabilities']) + BalanceSheetService::total($sheet['equity']), 2));

    $flow = app(FinancialStatements::class)->cashFlow('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($flow['closing'])->toBe(0.0)
        ->and(collect($flow['rows'])->firstWhere('key', 'non_cash')['label'])->toContain('4.600');
});

it('moves production costs from materials through work in progress to finished goods, rejects to production variance', function () {
    $service = app(WorkOrderService::class);
    $workOrder = $service->create(['warehouse_id' => $this->f['materialWarehouse']->id, 'goods_warehouse_id' => $this->f['warehouse']->id, 'cut_date' => '2026-10-01'], [['variant_id' => $this->f['variant']->id, 'qty_cut' => 10]]);

    $this->ledger->sync();
    expect(($this->balance)('wip'))->toBe(600.0)->and(($this->balance)('inventory_material'))->toBe(60000.0 - 600);

    $service->complete($workOrder, [$workOrder->items()->first()->id => ['qty_good' => 8, 'qty_reject' => 2]], '2026-10-05');
    $this->ledger->sync();

    expect(($this->balance)('wip'))->toBe(0.0)
        ->and(($this->balance)('inventory_fg'))->toBe(6000.0 + 480)
        ->and(($this->balance)('production_variance'))->toBe(120.0);
});

it('maps expense categories to their accounts, including production salaries into cost of goods sold', function () {
    $production = ExpenseCategory::query()->where('code', 'GAJI-PRODUKSI')->firstOrFail();
    $office = ExpenseCategory::query()->where('code', 'GAJI')->firstOrFail();
    Expense::create(['expense_date' => '2026-10-31', 'category_id' => $production->id, 'amount' => 3000, 'cash_account_id' => $this->bank->id]);
    Expense::create(['expense_date' => '2026-10-31', 'category_id' => $office->id, 'amount' => 2000, 'cash_account_id' => $this->bank->id]);

    $rows = app(FinancialStatements::class)->incomeStatement('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['rows'];
    $amount = fn (string $key): ?float => collect($rows)->firstWhere('key', $key)['amount'] ?? null;

    expect($amount('production_overhead'))->toBe(-3000.0)
        ->and($amount('hpp'))->toBe(-3000.0)
        ->and($amount('salary_expense'))->toBe(-2000.0)
        ->and($amount('total_opex'))->toBe(-2000.0);
});

it('books opening opnames to opening equity and routine opnames to inventory variance, for goods and materials', function () {
    $inventory = app(InventoryService::class);

    $goods = StockOpname::create(['warehouse_id' => $this->f['warehouse']->id, 'counted_at' => '2026-10-01 10:00', 'is_opening' => true]);
    $goods->items()->create(['variant_id' => $this->f['variant']->id, 'system_qty' => 100, 'counted_qty' => 110]);
    $inventory->postOpname($goods);

    $materials = MaterialOpname::create(['warehouse_id' => $this->f['materialWarehouse']->id, 'counted_at' => '2026-10-02 10:00']);
    $inventory->fillMaterialOpname($materials);
    $materials->items()->first()->update(['counted_qty' => 900]);
    $inventory->postMaterialOpname($materials);

    $this->ledger->sync();

    expect(($this->balance)('inventory_fg'))->toBe(6000.0 + 600)
        ->and(($this->balance)('opening_equity'))->toBe(-(60000.0 + 6000 + 600))
        ->and(($this->balance)('inventory_material'))->toBe(60000.0 - 6000)
        ->and(($this->balance)('inventory_variance'))->toBe(6000.0)
        ->and((float) DB::table('material_balances')->where('material_id', $this->f['material']->id)->value('qty'))->toBe(900.0);
});

it('locks closed months for transactions and journals, reports drift and reopens', function () {
    fixtureOrder($this->f, 10, '2026-09-05');
    $category = ExpenseCategory::create(['code' => 'SEWA', 'name' => 'Sewa']);

    $this->ledger->close(Carbon::parse('2026-09-01'));

    expect(fn () => Expense::create(['expense_date' => '2026-09-10', 'category_id' => $category->id, 'amount' => 100]))
        ->toThrow(BusinessRuleException::class, 'sudah ditutup buku')
        ->and(fn () => $this->ledger->close(Carbon::parse('2026-11-01')))->toThrow(BusinessRuleException::class, 'sudah berakhir');

    Expense::create(['expense_date' => '2026-10-10', 'category_id' => $category->id, 'amount' => 100]);

    // perubahan di luar aplikasi pada periode tertutup: jurnal tetap, selisihnya dilaporkan
    DB::table('sales_order_items')->update(['reseller_unit_price' => 120]);
    $result = $this->ledger->sync();

    expect($result['drift'])->not->toBeEmpty()
        ->and(($this->balance)('sales', null, '2026-09-30'))->toBe(-1000.0);

    $this->ledger->reopen(Carbon::parse('2026-09-01'));
    expect(($this->balance)('sales', null, '2026-09-30'))->toBe(-1200.0);
});

it('creates balanced manual journals and renders the accounting pages', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
    fixtureOrder($this->f, 10, '2026-10-05');

    $cash = Account::forCashAccount($this->bank);
    $opening = Account::idForRole('opening_equity');

    Livewire::test(CreateJournalEntry::class)
        ->fillForm(['entry_date' => '2026-10-01', 'description' => 'Saldo awal kas', 'lines' => [
            ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $opening, 'debit' => 0, 'credit' => 900],
        ]])
        ->call('create')
        ->assertHasErrors();

    Livewire::test(CreateJournalEntry::class)
        ->fillForm(['entry_date' => '2026-10-01', 'description' => 'Saldo awal kas', 'lines' => [
            ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $opening, 'debit' => 0, 'credit' => 1000],
        ]])
        ->call('create')
        ->assertHasNoErrors();

    $entry = JournalEntry::query()->where('is_auto', false)->firstOrFail();
    expect($entry->number)->toBe('JU-2610-0001')
        ->and(app(FinancialStatements::class)->cashBalance('konveksi', Carbon::parse('2026-10-31')))->toBe(1000.0);

    // jurnal manual tetap ada setelah jurnal otomatis disusun ulang
    $this->ledger->sync();
    expect(JournalEntry::query()->whereKey($entry->id)->exists())->toBeTrue();

    foreach (['bagan-akun', 'jurnal', 'jurnal/'.$entry->id, 'jurnal/create', 'buku-besar', 'neraca-saldo', 'tutup-buku'] as $path) {
        $this->get("/administrator/akuntansi/{$path}")->assertOk();
    }

    Livewire::test(GeneralLedgerPage::class, ['account' => $cash->id])
        ->set('preset', 'custom')->set('from', '2026-10-01')->set('until', '2026-10-31')
        ->assertSee('Saldo awal kas');
    Livewire::test(TrialBalance::class)->set('preset', 'previous_month')->assertSee('TOTAL')->callAction('excel')->assertFileDownloaded();
    Livewire::test(LedgerClosingPage::class)->assertSee('Oktober 2026');
    $this->get('/administrator/material-opnames')->assertOk();
});

it('books accessory purchases as cost of goods without deducting them again in the recipe based monthly profit', function () {
    fixtureOrder($this->f, 10, '2026-10-05'); // tagihan 1.000, HPP resep 600 (taksiran)
    $accessories = ExpenseCategory::where('code', 'AKSESORIS')->sole();
    Expense::create(['expense_date' => '2026-10-10', 'category_id' => $accessories->id, 'amount' => 50, 'cash_account_id' => $this->bank->id]);

    $result = app(FinancialStatements::class)->incomeStatement('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect($accessories->account->code)->toBe('5-1310')
        ->and(($this->balance)('recipe_cost'))->toBe(50.0)
        ->and($result['net_profit'])->toBe(350.0)
        ->and((float) DB::scalar("SELECT expenses FROM v_konveksi_monthly_pl WHERE month = '2026-10-01'"))->toBe(0.0);
});
