<?php

use App\Filament\Pages\BalanceSheet;
use App\Filament\Pages\Reports\AnnualReportPage;
use App\Filament\Pages\Reports\CashFlowStatement;
use App\Filament\Pages\Reports\EquityStatement;
use App\Filament\Pages\Reports\FinancialReportPage;
use App\Filament\Pages\Reports\IncomeStatement;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\EquityTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ResellerPayment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AnnualReport;
use App\Services\BalanceSheetService;
use App\Services\CashLedger;
use App\Services\FinancialStatements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo('2026-11-15');
    $this->f = businessFixture();
    $this->bank = CashAccount::create(['name' => 'BCA']);
    $this->fs = app(FinancialStatements::class);
    $this->amount = fn (array $rows, string $key): ?float => collect($rows)->firstWhere('key', $key)['amount'] ?? null;
});

it('builds the konveksi income statement from the ledger consistent with the monthly profit view, without tax by default', function () {
    fixtureOrder($this->f, 10, '2026-10-05'); // tagihan 1.000, HPP 600
    $rent = ExpenseCategory::create(['code' => 'SEWA', 'name' => 'Sewa']);
    Expense::create(['expense_date' => '2026-10-10', 'category_id' => $rent->id, 'amount' => 100, 'cash_account_id' => $this->bank->id]);

    $result = $this->fs->incomeStatement('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    $view = (float) DB::scalar("SELECT net_profit FROM v_konveksi_monthly_pl WHERE month = '2026-10-01'");

    expect(($this->amount)($result['rows'], 'net_sales'))->toBe(1000.0)
        ->and(($this->amount)($result['rows'], 'cogs_production'))->toBe(-600.0)
        ->and(($this->amount)($result['rows'], 'gross_profit'))->toBe(400.0)
        ->and(($this->amount)($result['rows'], 'account:'.$rent->account->code))->toBe(-100.0)
        ->and($rent->account->name)->toBe('Beban Sewa')
        ->and($result['profit_before_tax'])->toBe(300.0)->toBe($view)
        ->and($result['tax'])->toBe(0.0)
        ->and($result['net_profit'])->toBe(300.0)
        ->and(collect($result['rows'])->pluck('key'))->not->toContain('tax')
        ->and(DB::table('journal_entries')->where('source_type', 'tax')->exists())->toBeFalse();

    // pajak bisa diaktifkan lagi lewat Pengaturan: tax_rate
    Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => 0.11]);
    $result = $this->fs->incomeStatement('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($result['tax'])->toBe(33.0)->and($result['net_profit'])->toBe(267.0);

    Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => 0.2]);
    expect($this->fs->incomeStatement('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['tax'])->toBe(60.0);

    // rugi bulan berikutnya: pajak kumulatif tahun berjalan dikoreksi kembali
    Expense::create(['expense_date' => '2026-11-05', 'category_id' => $rent->id, 'amount' => 1000, 'cash_account_id' => $this->bank->id]);
    expect($this->fs->incomeStatement('konveksi', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-15'))['tax'])->toBe(-60.0)
        ->and($this->fs->incomeStatement('konveksi', Carbon::parse('2026-01-01'), Carbon::parse('2026-11-15'))['tax'])->toBe(0.0);
});

it('builds the store income statement from the store monthly profit', function () {
    fixtureOrder($this->f, 10, '2026-10-05'); // omzet 1.500, harga konveksi 1.000

    $result = $this->fs->incomeStatement('store:'.$this->f['store']->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect(($this->amount)($result['rows'], 'store_sales'))->toBe(1500.0)
        ->and(($this->amount)($result['rows'], 'store_cogs'))->toBe(-1000.0)
        ->and($result['profit_before_tax'])->toBe((float) DB::scalar('SELECT SUM(net_profit) FROM v_store_monthly_pl'))
        ->and($this->fs->incomeStatement('stores', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['net_profit'])->toBe($result['net_profit']);
});

it('reconciles the cash flow with the cash ledger and balance sheet, including capital and drawings', function () {
    fixtureOrder($this->f, 10, '2026-09-05');
    EquityTransaction::create(['transacted_at' => '2026-09-01', 'type' => 'setoran', 'amount' => 5000, 'cash_account_id' => $this->bank->id]);
    ResellerPayment::create(['reseller_id' => $this->f['reseller']->id, 'paid_at' => '2026-10-02', 'amount' => 700, 'method' => 'transfer', 'cash_account_id' => $this->bank->id]);
    EquityTransaction::create(['transacted_at' => '2026-10-20', 'type' => 'prive', 'amount' => 300]);

    $flow = $this->fs->cashFlow('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    $bankAccount = Account::query()->where('cash_account_id', $this->bank->id)->firstOrFail();

    expect($flow['opening'])->toBe(app(CashLedger::class)->balance(Carbon::parse('2026-09-30')))->toBe(5000.0)
        ->and(($this->amount)($flow['rows'], 'cash:ar_reseller'))->toBe(700.0)
        ->and(($this->amount)($flow['rows'], 'cash:equity_drawing'))->toBe(-300.0)
        ->and($flow['closing'])->toBe(round($flow['opening'] + 400, 2))
        ->and($flow['closing'])->toBe(BalanceSheetService::sumRoles(app(BalanceSheetService::class)->konveksiSheet(Carbon::parse('2026-10-31'))['assets'], ['cash']))
        ->and(($this->amount)($flow['rows'], 'account:'.$bankAccount->code))->toBe(5700.0)
        ->and(($this->amount)($flow['rows'], 'account:1-1199'))->toBe(-300.0);

    $sheet = app(BalanceSheetService::class)->konveksiSheet(Carbon::parse('2026-10-31'));
    expect(collect($sheet['equity'])->firstWhere('key', '3-1000')['amount'])->toBe(5000.0)
        ->and(collect($sheet['equity'])->firstWhere('key', '3-2000')['amount'])->toBe(-300.0)
        ->and(BalanceSheetService::total($sheet['assets']))->toBe(round(BalanceSheetService::total($sheet['liabilities']) + BalanceSheetService::total($sheet['equity']), 2));
});

it('explains the change in equity from capital, drawings and profit', function () {
    EquityTransaction::create(['transacted_at' => '2026-10-01', 'type' => 'setoran', 'amount' => 5000, 'cash_account_id' => $this->bank->id]);
    EquityTransaction::create(['transacted_at' => '2026-10-15', 'type' => 'prive', 'amount' => 1000]);
    fixtureOrder($this->f, 10, '2026-10-05');

    $statement = $this->fs->equityStatement('konveksi', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect(($this->amount)($statement['rows'], 'deposits'))->toBe(5000.0)
        ->and(($this->amount)($statement['rows'], 'drawings'))->toBe(-1000.0)
        ->and($statement['unexplained'])->toBe(0.0)
        ->and($statement['closing'])->toBe($this->fs->totalEquity('konveksi', Carbon::parse('2026-10-31')))
        ->and(round($statement['opening'] + 5000 - 1000 + ($this->amount)($statement['rows'], 'opening_balances') + ($this->amount)($statement['rows'], 'net_profit'), 2))->toBe($statement['closing']);
});

it('keeps rows that only exist in the comparison period', function () {
    $rows = FinancialReportPage::merge([
        [['key' => 'a', 'label' => 'A', 'amount' => 1.0, 'type' => 'line', 'indent' => 1], ['key' => 'total', 'label' => 'T', 'amount' => 1.0, 'type' => 'total', 'indent' => 0]],
        [['key' => 'a', 'label' => 'A', 'amount' => 2.0, 'type' => 'line', 'indent' => 1], ['key' => 'expense:9', 'label' => 'Lama', 'amount' => -5.0, 'type' => 'line', 'indent' => 1], ['key' => 'total', 'label' => 'T', 'amount' => -3.0, 'type' => 'total', 'indent' => 0]],
    ]);

    expect(array_column($rows, 'key'))->toBe(['a', 'expense:9', 'total'])
        ->and($rows[1]['amounts'])->toBe([null, -5.0]);
});

it('renders the financial report pages with comparison, print and excel export', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
    fixtureOrder($this->f, 10, '2026-10-05');

    foreach (['laba-rugi' => 'Laporan Laba Rugi', 'arus-kas' => 'Laporan Arus Kas', 'perubahan-modal' => 'Laporan Perubahan Modal', 'tahunan' => 'Laporan Tahunan'] as $slug => $title) {
        $this->get("/administrator/laporan-keuangan/{$slug}")->assertOk()->assertSee($title);
    }

    foreach ([IncomeStatement::class, CashFlowStatement::class, EquityStatement::class] as $page) {
        Livewire::test($page)
            ->set('preset', 'previous_month')
            ->set('compare', 'previous')
            ->assertSee('Perubahan')
            ->set('entity', 'store:'.$this->f['store']->id)
            ->assertOk()
            ->callAction('print')
            ->callAction('excel')
            ->assertFileDownloaded();
    }

    Livewire::test(IncomeStatement::class)->set('preset', 'previous_month')->assertSee('LABA BERSIH')->assertSee('Rp1.000');

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Laba Rugi per Bulan')
        ->assertSee('Rata-rata umur piutang')
        ->callAction('print')
        ->callAction('excel')
        ->assertFileDownloaded('laporan-tahunan-2026.xlsx');

    Livewire::test(BalanceSheet::class)->callAction('print')->callAction('excel')->assertFileDownloaded();

    expect(app(AnnualReport::class)->build(2026)['kpis'][0]['value'])->toBe(1000.0);
});
