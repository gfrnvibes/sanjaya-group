<?php

namespace App\Services;

use App\Models\Store;
use App\Support\ChartOfAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * NERACA per sumber (konveksi, gabungan toko & tiap toko) per tanggal, dari saldo BUKU BESAR.
 *
 * Akun ber-dimensi dipisah per lawan transaksi: piutang reseller (saldo kredit = deposit reseller),
 * utang supplier (saldo debit = uang muka), utang ke konveksi (saldo debit = lebih bayar).
 * Laba ditahan = akun laba ditahan + laba rugi tahun-tahun sebelumnya; laba tahun berjalan setelah pajak.
 *
 * Baris: ['key', 'label', 'amount', 'role'].
 */
class BalanceSheetService
{
    public const KONVEKSI = GeneralLedger::KONVEKSI;

    public const ALL_STORES = GeneralLedger::ALL_STORES;

    /** Akun yang saldonya dipisah per dimensi: [dimensi, label lawan, bagian lawan]. */
    private const SPLIT = [
        'ar_reseller' => ['reseller_id', 'Deposit / Titipan Reseller', 'liabilities'],
        'ap_supplier' => ['supplier_id', 'Uang Muka Supplier', 'assets'],
        'store_payable' => ['store_id', 'Lebih Bayar ke Konveksi', 'assets'],
    ];

    public function __construct(private GeneralLedger $ledger) {}

    /**
     * @return array<string, array{label: string, assets: list<array<string, mixed>>, liabilities: list<array<string, mixed>>, equity: list<array<string, mixed>>, ytd_profit: float}>
     */
    public function build(Carbon $asOf): array
    {
        $asOf = $asOf->copy()->startOfDay();
        $this->ledger->ensureFresh();
        $sheets = [self::KONVEKSI => $this->sheet(self::KONVEKSI, $asOf)];

        $storeIds = DB::table('journal_lines')->whereNotNull('store_id')->where('entry_date', '<=', $asOf->toDateString())->distinct()->pluck('store_id');

        if ($storeIds->isEmpty()) {
            return $sheets;
        }

        $sheets[self::ALL_STORES] = $this->sheet(self::ALL_STORES, $asOf, 'Semua Toko (gabungan)');

        foreach (Store::withTrashed()->with('reseller:id,name')->whereIn('id', $storeIds)->orderBy('name')->get() as $store) {
            $sheets['store:'.$store->id] = $this->sheet('store:'.$store->id, $asOf, $store->name.' ('.$store->reseller?->name.')');
        }

        return $sheets;
    }

    /**
     * Neraca konveksi saja (ringkasan dashboard).
     *
     * @return array{label: string, assets: list<array<string, mixed>>, liabilities: list<array<string, mixed>>, equity: list<array<string, mixed>>, ytd_profit: float}
     */
    public function konveksiSheet(Carbon $asOf): array
    {
        return $this->sheet(self::KONVEKSI, $asOf->copy()->startOfDay());
    }

    /**
     * @param  list<array{amount: float}>  $lines
     */
    public static function total(array $lines): float
    {
        return round(array_sum(array_column($lines, 'amount')), 2);
    }

    /**
     * Jumlah baris dengan role tertentu (mis. 'cash' = semua kas & bank).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $roles
     */
    public static function sumRoles(array $lines, array $roles): float
    {
        return round(collect($lines)->whereIn('role', $roles)->sum('amount'), 2);
    }

    /**
     * @return array{label: string, assets: list<array<string, mixed>>, liabilities: list<array<string, mixed>>, equity: list<array<string, mixed>>, ytd_profit: float}
     */
    public function sheet(string $entity, Carbon $asOf, ?string $label = null): array
    {
        $yearStart = $asOf->copy()->startOfYear();
        $all = $this->ledger->balances($entity, null, $asOf);
        $profitTypes = ChartOfAccounts::PROFIT_TYPES;

        $sections = ['assets' => [], 'liabilities' => [], 'equity' => []];

        foreach ($all->whereIn('type', ['asset', 'liability', 'equity']) as $account) {
            $section = match ($account->type) {
                'asset' => 'assets', 'liability' => 'liabilities', default => 'equity',
            };
            $sign = $account->type === 'asset' ? 1 : -1;

            if (isset(self::SPLIT[$account->role])) {
                [$dimension, $counterLabel, $counterSection] = self::SPLIT[$account->role];
                $balances = $this->ledger->balancesBy($entity, $account->role, $dimension, $asOf);
                $main = round(array_sum(array_filter($balances, fn (float $net): bool => $sign * $net > 0)) * $sign, 2);
                $counter = round(abs(array_sum(array_filter($balances, fn (float $net): bool => $sign * $net < 0))), 2);

                $sections[$section][] = $this->line($account, $account->name, $main);

                if ($counter != 0) {
                    $sections[$counterSection][] = ['key' => $account->code.'-counter', 'label' => $counterLabel, 'amount' => $counter, 'role' => $account->role.'_counter'];
                }

                continue;
            }

            if ($account->role === 'retained_earnings' || $account->role === 'store_retained_earnings' || $account->net == 0) {
                continue;
            }

            $name = $account->role === 'inventory_fg' && $account->net < 0 ? $account->name.' (minus: stok awal belum dicatat, tunggu opname awal)' : $account->name;
            $sections[$section][] = $this->line($account, $name, $sign * $account->net);
        }

        $profitBefore = round(-$this->ledger->balances($entity, null, $yearStart->copy()->subDay(), $profitTypes)->sum('net'), 2);
        $retained = round(-$all->whereIn('role', ['retained_earnings', 'store_retained_earnings'])->sum('net') + $profitBefore, 2);
        $ytd = $this->ledger->balances($entity, $yearStart, $asOf, $profitTypes);
        $ytdAfterTax = round(-$ytd->sum('net'), 2);
        $ytdBeforeTax = round(-$ytd->where('type', '!=', 'tax')->sum('net'), 2);

        $sections['equity'][] = ['key' => 'retained_earnings', 'label' => 'Laba Ditahan (tahun-tahun sebelumnya)', 'amount' => $retained, 'role' => 'retained_earnings'];
        $sections['equity'][] = ['key' => 'ytd_profit', 'label' => 'Laba Tahun Berjalan'.(FinancialStatements::taxEnabled() ? ' (setelah pajak)' : ''), 'amount' => $ytdAfterTax, 'role' => 'ytd_profit'];

        return [
            'label' => $label ?? 'Konveksi',
            'assets' => $this->visible($sections['assets']),
            'liabilities' => $this->visible($sections['liabilities']),
            'equity' => array_values(array_filter($sections['equity'], fn (array $line): bool => $line['amount'] != 0 || in_array($line['key'], ['retained_earnings', 'ytd_profit'], true))),
            'ytd_profit' => $ytdBeforeTax,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(object $account, string $label, float $amount): array
    {
        $isCash = $account->cash_account_id !== null || in_array($account->role, ['cash_unassigned', 'store_cash'], true);

        return ['key' => $account->code, 'label' => $label, 'amount' => round($amount, 2) + 0.0, 'role' => $isCash ? 'cash' : $account->role];
    }

    /**
     * Akun utama (piutang, persediaan) tetap tampil walau 0; selebihnya hanya yang bersaldo.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function visible(array $lines): array
    {
        $always = ['ar_reseller', 'inventory_fg', 'inventory_material', 'store_payable'];

        return array_values(array_filter($lines, fn (array $line): bool => $line['amount'] != 0 || in_array($line['role'], $always, true)));
    }

    /**
     * @return Collection<int, object>
     */
    public function accounts(string $entity, Carbon $asOf): Collection
    {
        return $this->ledger->balances($entity, null, $asOf);
    }
}
