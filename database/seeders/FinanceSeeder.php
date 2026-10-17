<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Reseller;
use App\Models\Store;
use App\Services\InvoiceService;
use Illuminate\Database\Seeder;

/**
 * Tagihan reseller per bulan, pembayaran (transfer, tunai, potongan biaya), dan biaya operasional.
 */
class FinanceSeeder extends Seeder
{
    public function run(InvoiceService $invoices): void
    {
        $bca = CashAccount::query()->where('name', 'BCA Konveksi')->value('id');
        $cash = CashAccount::query()->where('name', 'Kas Tunai')->value('id');
        $category = fn (string $code): int => ExpenseCategory::query()->where('code', $code)->value('id');

        // Tagihan Agustus & September terbit, Oktober masih draft.
        foreach (Reseller::query()->get() as $reseller) {
            foreach ([['2026-08-01', '2026-08-31', true], ['2026-09-01', '2026-09-30', true], ['2026-10-01', '2026-10-31', false]] as [$start, $end, $issue]) {
                $invoice = $invoices->generate([
                    'reseller_id' => $reseller->id,
                    'period_start' => $start,
                    'period_end' => $end,
                    'due_date' => now()->parse($end)->addDays(10)->toDateString(),
                ]);

                if ($invoice->items()->doesntExist()) {
                    $invoices->void($invoice);

                    continue;
                }

                if ($issue) {
                    $invoice->update(['issued_at' => now()->parse($end)->addDay()->toDateString()]);
                    $invoices->issue($invoice);
                }
            }
        }

        // Pembayaran: ARR lunas Agustus, DWI sebagian, BMS CHEF sebagian lewat potongan biaya.
        $payments = [
            ['ARR SANJAYA', fn ($r) => $r->invoices()->where('period_start', '2026-08-01')->first()?->netTotal() + (float) $r->opening_balance, '2026-09-05 10:00:00', PaymentMethod::Transfer, $bca, 'Pelunasan Agustus + saldo awal'],
            ['ARR SANJAYA', fn () => 1500000, '2026-10-06 10:00:00', PaymentMethod::Transfer, $bca, 'Cicilan September'],
            ['DAPUR DWI', fn ($r) => round(($r->invoices()->where('period_start', '2026-08-01')->first()?->netTotal() ?? 0) * 0.6, -3), '2026-09-10 15:00:00', PaymentMethod::Transfer, $bca, 'Bayar sebagian Agustus'],
            ['DAPUR DWI', fn () => 500000, '2026-09-28 11:00:00', PaymentMethod::Cash, $cash, 'Titip tunai saat ambil barang'],
            ['BMS CHEF', fn () => 450000, '2026-09-08 09:00:00', PaymentMethod::Transfer, $bca, 'Pelunasan saldo awal'],
            ['BMS UNIFORM', fn () => 300000, '2026-10-02 09:00:00', PaymentMethod::Transfer, $bca, 'Pembayaran Agustus'],
        ];

        foreach ($payments as [$name, $amount, $paidAt, $method, $account, $note]) {
            $reseller = Reseller::query()->where('name', $name)->firstOrFail();
            $value = (float) $amount($reseller);

            if ($value > 0) {
                $reseller->payments()->create(['paid_at' => $paidAt, 'amount' => $value, 'method' => $method, 'cash_account_id' => $account, 'note' => $note]);
            }
        }

        // Potongan biaya: BMS CHEF membayar ongkir konveksi, dicatat sebagai pembayaran + biaya.
        $bms = Reseller::query()->where('name', 'BMS CHEF')->firstOrFail();
        $offset = $bms->payments()->create([
            'paid_at' => '2026-09-20 13:00:00',
            'amount' => 185000,
            'method' => PaymentMethod::CostOffset,
            'note' => 'Ongkir kirim bahan dibayar reseller',
        ]);
        Expense::create([
            'expense_date' => '2026-09-20',
            'category_id' => $category('ONGKIR'),
            'amount' => 185000,
            'reseller_id' => $bms->id,
            'reseller_payment_id' => $offset->id,
            'note' => 'Ongkir kirim bahan (dibayar BMS CHEF)',
        ]);

        // Biaya operasional bulanan.
        $adminStore = Store::query()->where('name', 'ARR SANJAYA Shopee')->value('id');

        foreach (['2026-08', '2026-09', '2026-10'] as $month) {
            Expense::create(['expense_date' => "{$month}-01", 'category_id' => $category('SEWA'), 'amount' => 1200000, 'cash_account_id' => $bca, 'note' => 'Sewa tempat produksi']);
            Expense::create(['expense_date' => "{$month}-05", 'category_id' => $category('LISTRIK_AIR'), 'amount' => mt_rand(35, 50) * 10000, 'cash_account_id' => $bca, 'note' => 'Tagihan PLN & PDAM']);
            Expense::create(['expense_date' => "{$month}-25", 'category_id' => $category('GAJI_STAF'), 'amount' => 2500000, 'cash_account_id' => $bca, 'note' => 'Gaji staf gudang']);
            Expense::create(['expense_date' => "{$month}-25", 'category_id' => $category('GAJI_ADMIN_TOKO'), 'amount' => 1000000, 'store_id' => $adminStore, 'cash_account_id' => $bca, 'note' => 'Gaji admin toko Shopee']);
            Expense::create(['expense_date' => "{$month}-10", 'category_id' => $category('PACKING'), 'amount' => mt_rand(20, 35) * 10000, 'cash_account_id' => $cash, 'note' => 'Plastik, lakban, label']);
        }
    }
}
