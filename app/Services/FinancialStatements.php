<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Store;
use App\Support\ChartOfAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAPORAN KEUANGAN dari BUKU BESAR (jurnal double-entry): Laba Rugi, Arus Kas & Perubahan Modal
 * untuk KONVEKSI, tiap TOKO, atau gabungan toko. Neraca: BalanceSheetService.
 *
 * Baris laporan: ['key', 'label', 'amount', 'type' => header|line|subtotal|total|note, 'indent'].
 * key = role akun (mis. sales, cogs_production) atau 'account:{kode}' untuk akun tanpa role.
 * Toko dibukukan per bulan (dari laba rugi toko bulanan), jadi periode toko dibulatkan ke bulan penuh.
 */
class FinancialStatements
{
    public const KONVEKSI = GeneralLedger::KONVEKSI;

    public const ALL_STORES = GeneralLedger::ALL_STORES;

    /** Baris laba rugi yang selalu tampil walau 0. */
    private const ALWAYS = ['sales', 'cogs_production', 'cogs_ready_made', 'store_sales', 'store_cogs', 'store_admin_fee', 'store_ads'];

    /** Label arus kas per role akun lawan. */
    private const CASH_LABELS = [
        'ar_reseller' => 'Penerimaan dari reseller',
        'offset_clearing' => 'Potongan biaya dibayar reseller',
        'inventory_material' => 'Pembelian bahan baku',
        'inventory_fg' => 'Pembelian barang jadi / borongan tunai',
        'ap_supplier' => 'Pembayaran ke supplier',
        'wages_payable' => 'Pembayaran upah jahit & potong',
        'tax_payable' => 'Pembayaran pajak',
        'equity_capital' => 'Setoran modal pemilik',
        'equity_drawing' => 'Prive (penarikan pemilik)',
        'opening_equity' => 'Saldo awal kas (jurnal saldo awal)',
        'store_sales' => 'Penerimaan hasil penjualan',
        'store_admin_fee' => 'Fee admin marketplace',
        'store_ads' => 'Iklan',
        'store_payable' => 'Pembayaran ke konveksi',
        'store_tax_payable' => 'Pembayaran pajak',
    ];

    public function __construct(private GeneralLedger $ledger) {}

    /** Tarif pajak penghasilan (Pengaturan: tax_rate). 0 = pajak tidak dihitung (bawaan). */
    public static function taxRate(): float
    {
        return (float) Setting::valueOf('tax_rate', 0);
    }

    public static function taxEnabled(): bool
    {
        return self::taxRate() > 0;
    }

    /** Pajak dari laba sebelum pajak; rugi = 0. */
    public static function tax(float $profitBeforeTax): float
    {
        return round(max($profitBeforeTax, 0) * self::taxRate(), 2);
    }

    /**
     * Pilihan entitas laporan: konveksi, gabungan toko, lalu tiap toko.
     *
     * @return array<string, string>
     */
    public static function entities(): array
    {
        return [
            self::KONVEKSI => 'Konveksi',
            self::ALL_STORES => 'Semua toko (gabungan)',
            ...Store::query()->with('reseller:id,name')->orderBy('name')->get()
                ->mapWithKeys(fn (Store $store): array => ['store:'.$store->id => 'Toko: '.$store->name.' ('.$store->reseller?->name.')'])
                ->all(),
        ];
    }

    /**
     * Periode efektif: toko memakai bulan penuh (jurnal toko per bulan), akhir periode maks. hari ini.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function period(string $entity, Carbon $from, Carbon $until): array
    {
        $until = $until->copy()->min(today());
        $from = $from->copy()->min($until);

        return $entity === self::KONVEKSI
            ? [$from->startOfDay(), $until->startOfDay()]
            : [$from->startOfMonth(), $until->endOfMonth()->min(today())->startOfDay()];
    }

    // ------------------------------------------------------------------ LABA RUGI

    /**
     * @return array{rows: list<array<string, mixed>>, profit_before_tax: float, tax: float, net_profit: float, net_sales: float}
     */
    public function incomeStatement(string $entity, Carbon $from, Carbon $until): array
    {
        $accounts = $this->ledger->balances($entity, $from, $until, ['revenue', 'cogs', 'expense', 'other_income', 'other_expense', 'tax'])
            ->filter(fn (object $row): bool => $row->debit != 0 || $row->credit != 0 || in_array($row->role, self::ALWAYS, true));
        $store = $entity !== self::KONVEKSI;

        // Akun default yang belum pernah dipakai tetap tampil (0) agar struktur laporan tetap.
        foreach (DB::table('accounts')->where('ledger', GeneralLedger::ledgerOf($entity))->whereIn('role', self::ALWAYS)->get() as $account) {
            if (! $accounts->contains('id', $account->id)) {
                $accounts->push((object) ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'role' => $account->role, 'debit' => 0.0, 'credit' => 0.0, 'net' => 0.0]);
            }
        }

        $group = fn (string $type): Collection => $accounts->where('type', $type)->sortBy('code')->values();
        $sum = fn (Collection $rows): float => round($rows->sum(fn (object $row): float => -$row->net), 2);
        $lines = fn (Collection $rows): array => $rows->map(fn (object $row): array => self::line(self::key($row), $row->name, -$row->net))->all();

        $revenue = $group('revenue');
        $cogs = $group('cogs');
        $expenses = $group('expense');
        $other = $accounts->whereIn('type', ['other_income', 'other_expense'])->sortBy('code')->values();

        $netSales = $sum($revenue);
        $totalCogs = $sum($cogs);
        $grossProfit = round($netSales + $totalCogs, 2);
        $totalExpenses = $sum($expenses);
        $operatingProfit = round($grossProfit + $totalExpenses, 2);
        $otherTotal = $sum($other);
        $profitBeforeTax = round($operatingProfit + $otherTotal, 2);
        $tax = -$sum($group('tax'));
        $netProfit = round($profitBeforeTax - $tax, 2);

        $rows = [
            self::header('revenue', 'Pendapatan'),
            ...$lines($revenue),
            self::subtotal('net_sales', $store ? 'Omzet bersih' : 'Penjualan bersih', $netSales),
            self::header('cogs', 'Harga Pokok Penjualan (HPP)'),
            ...$lines($cogs),
            self::subtotal('hpp', 'Total HPP', $totalCogs),
            self::total('gross_profit', 'LABA KOTOR', $grossProfit, $netSales),
            self::header('opex', $store ? 'Beban Penjualan' : 'Beban Operasional'),
            ...$lines($expenses),
            ...($expenses->isEmpty() ? [self::note('no_expense', 'Belum ada biaya operasional tercatat pada periode ini.')] : []),
            self::subtotal('total_opex', $store ? 'Total beban penjualan' : 'Total beban operasional', $totalExpenses),
            self::total('operating_profit', 'LABA OPERASIONAL', $operatingProfit, $netSales),
            self::header('other', 'Pendapatan & Beban Lain-lain'),
            ...$lines($other),
            self::subtotal('other_total', 'Total lain-lain', $otherTotal),
            // Pajak hanya tampil bila diaktifkan (tarif > 0) atau ada jurnal pajak manual.
            ...(self::taxEnabled() || $tax != 0 ? [
                self::subtotal('profit_before_tax', 'Laba sebelum pajak', $profitBeforeTax),
                self::line('tax', 'Pajak penghasilan ('.self::percent(self::taxRate()).' dari laba kumulatif tahun berjalan)', -$tax),
            ] : []),
            self::total('net_profit', 'LABA BERSIH', $netProfit, $netSales),
        ];

        return ['rows' => $rows, 'profit_before_tax' => $profitBeforeTax, 'tax' => $tax, 'net_profit' => $netProfit, 'net_sales' => $netSales];
    }

    // ------------------------------------------------------------------ ARUS KAS

    /**
     * Metode langsung: arus kas per akun lawan pada jurnal yang menyentuh kas (transfer antar-kas tidak dihitung).
     *
     * @return array{rows: list<array<string, mixed>>, opening: float, closing: float}
     */
    public function cashFlow(string $entity, Carbon $from, Carbon $until): array
    {
        $this->ledger->ensureFresh();
        $cash = $this->cashAccountIds($entity);

        $opening = $this->cashBalance($entity, $from->copy()->subDay());
        $closing = $this->cashBalance($entity, $until);

        $flows = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereBetween('l.entry_date', [$from->toDateString(), $until->toDateString()])
            ->whereNotIn('l.account_id', $cash ?: [0])
            ->whereExists(fn ($query) => $query->from('journal_lines as c')->whereColumn('c.entry_id', 'l.entry_id')->whereIn('c.account_id', $cash ?: [0]))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.role', 'a.cash_flow')
            ->selectRaw('a.id, a.code, a.name, a.type, a.role, a.cash_flow, SUM(l.credit - l.debit) AS amount')
            ->orderBy('a.code');
        $this->ledger->scope($flows, $entity);
        $flows = $flows->get()->filter(fn (object $row): bool => round((float) $row->amount, 2) != 0);

        $section = fn (string $flow): Collection => $flows->filter(fn (object $row): bool => ($row->cash_flow ?? 'operating') === $flow)->values();
        $lines = fn (Collection $rows): array => $rows->map(fn (object $row): array => self::line(
            'cash:'.($row->role ?? $row->code),
            self::CASH_LABELS[$row->role] ?? (in_array($row->type, ['expense', 'cogs', 'other_expense', 'tax'], true) ? 'Pembayaran '.lcfirst($row->name) : $row->name),
            (float) $row->amount,
        ))->all();
        $total = fn (Collection $rows): float => round($rows->sum(fn (object $row): float => (float) $row->amount), 2);

        [$operating, $investing, $financing] = [$section('operating'), $section('investing'), $section('financing')];

        $accounts = DB::table('accounts')->whereIn('id', $cash ?: [0])->orderBy('code')->get()
            ->map(fn (object $account): array => self::line('account:'.$account->code, $account->name, $this->cashBalance($entity, $until, [$account->id])))
            ->filter(fn (array $row): bool => $row['amount'] != 0 || $entity === self::KONVEKSI)
            ->values()->all();

        $nonCash = $entity === self::KONVEKSI ? $this->offsetTotal($from, $until) : 0.0;

        $rows = [
            self::line('opening', 'Saldo kas awal', $opening, 0),
            self::header('operating', 'Arus Kas dari Aktivitas Operasi'),
            ...$lines($operating),
            self::subtotal('operating_total', 'Kas bersih dari aktivitas operasi', $total($operating)),
            self::header('investing', 'Arus Kas dari Aktivitas Investasi'),
            ...($investing->isEmpty() ? [self::line('fixed_assets', 'Pembelian / penjualan aset tetap', 0.0)] : $lines($investing)),
            self::subtotal('investing_total', 'Kas bersih dari aktivitas investasi', $total($investing)),
            self::header('financing', 'Arus Kas dari Aktivitas Pendanaan'),
            ...($financing->isEmpty() ? [self::line('financing_none', 'Setoran modal, prive & pinjaman', 0.0)] : $lines($financing)),
            self::subtotal('financing_total', 'Kas bersih dari aktivitas pendanaan', $total($financing)),
            self::subtotal('net_change', 'Kenaikan (penurunan) kas', round($closing - $opening, 2)),
            self::total('closing', 'SALDO KAS AKHIR', $closing),
            ...($nonCash != 0 ? [self::note('non_cash', 'Transaksi non-kas: biaya / bahan / upah dibayar reseller (memotong piutang) Rp'.number_format($nonCash, 0, ',', '.').'.')] : []),
            self::header('accounts', 'Saldo akhir per rekening'),
            ...$accounts,
        ];

        return ['rows' => $rows, 'opening' => $opening, 'closing' => $closing];
    }

    // ------------------------------------------------------------------ PERUBAHAN MODAL

    /**
     * Modal awal + setoran - prive + saldo awal/penyesuaian + laba bersih = modal akhir.
     *
     * @return array{rows: list<array<string, mixed>>, opening: float, closing: float, unexplained: float}
     */
    public function equityStatement(string $entity, Carbon $from, Carbon $until): array
    {
        $opening = $this->totalEquity($entity, $from->copy()->subDay());
        $closing = $this->totalEquity($entity, $until);
        $netProfit = $this->incomeStatement($entity, $from, $until)['net_profit'];

        $equity = $this->ledger->balances($entity, $from, $until, ['equity']);
        $byRole = fn (array $roles): float => round(-$equity->whereIn('role', $roles)->sum('net'), 2);

        $deposits = $byRole(['equity_capital']);
        $drawings = $byRole(['equity_drawing']);
        $openingBalances = $byRole(['opening_equity', 'store_opening_equity']);
        $others = round(-$equity->sum('net') - $deposits - $drawings - $openingBalances, 2);
        $unexplained = round($closing - ($opening + $deposits + $drawings + $openingBalances + $others + $netProfit), 2);

        $rows = [
            self::line('opening', 'Modal awal periode', $opening, 0),
            self::line('deposits', 'Setoran modal', $deposits),
            self::line('drawings', 'Prive (penarikan pemilik)', $drawings),
            self::line('opening_balances', 'Saldo awal & penyesuaian (stok awal, opname awal, saldo awal piutang/kas)', $openingBalances),
            self::line('other_equity', 'Perubahan modal lain (jurnal manual)', $others),
            self::line('net_profit', 'Laba bersih periode'.(self::taxEnabled() ? ' (setelah pajak)' : ''), $netProfit),
            ...($unexplained != 0 ? [self::line('unexplained', 'Selisih (periksa jurnal tidak seimbang)', $unexplained)] : []),
            self::total('closing', 'MODAL AKHIR PERIODE', $closing),
        ];

        return ['rows' => $rows, 'opening' => $opening, 'closing' => $closing, 'unexplained' => $unexplained];
    }

    /**
     * Total ekuitas = saldo akun ekuitas + akumulasi laba rugi s.d. tanggal.
     */
    public function totalEquity(string $entity, Carbon $date): float
    {
        return round(-$this->ledger->balances($entity, null, $date, ['equity', 'revenue', 'cogs', 'expense', 'other_income', 'other_expense', 'tax'])->sum('net'), 2);
    }

    // ------------------------------------------------------------------ NERACA

    /**
     * Neraca 1 sumber (hasil BalanceSheetService) dalam format baris laporan.
     *
     * @param  array{assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>}  $sheet
     * @return list<array<string, mixed>>
     */
    public static function balanceRows(array $sheet): array
    {
        $section = fn (string $key, array $lines): array => array_map(
            fn (array $line): array => self::line($key.':'.($line['key'] ?? str($line['label'])->slug()), $line['label'], (float) $line['amount']),
            $lines,
        );
        $liabilities = BalanceSheetService::total($sheet['liabilities']);
        $equity = BalanceSheetService::total($sheet['equity']);

        return [
            self::header('assets', 'Aset'),
            ...$section('asset', $sheet['assets']),
            self::total('assets_total', 'TOTAL ASET', BalanceSheetService::total($sheet['assets'])),
            self::header('liabilities', 'Kewajiban'),
            ...$section('liability', $sheet['liabilities']),
            self::subtotal('liabilities_total', 'Total kewajiban', $liabilities),
            self::header('equity', 'Ekuitas'),
            ...$section('equity', $sheet['equity']),
            self::subtotal('equity_total', 'Total ekuitas', $equity),
            self::total('liabilities_equity', 'TOTAL KEWAJIBAN + EKUITAS', round($liabilities + $equity, 2)),
        ];
    }

    // ------------------------------------------------------------------ NERACA SALDO

    /**
     * Neraca saldo: per akun saldo awal, mutasi debit/kredit, saldo akhir debit/kredit.
     * Akun laba rugi dihitung sejak awal tahun buku; laba rugi tahun-tahun sebelumnya = baris laba ditahan.
     *
     * @return list<array<string, mixed>>
     */
    public function trialBalance(string $entity, Carbon $from, Carbon $until): array
    {
        $profitTypes = ['revenue', 'cogs', 'expense', 'other_income', 'other_expense', 'tax'];
        $yearStart = $from->copy()->startOfYear();
        $before = $from->copy()->subDay();

        $opening = $this->ledger->balances($entity, null, $before)->keyBy('id');
        $openingProfit = $this->ledger->balances($entity, $yearStart, $before, $profitTypes)->keyBy('id');
        $movement = $this->ledger->balances($entity, $from, $until)->keyBy('id');
        $priorProfit = round($this->ledger->balances($entity, null, $yearStart->copy()->subDay(), $profitTypes)->sum('net'), 2);

        $accounts = DB::table('accounts')->where('ledger', GeneralLedger::ledgerOf($entity))->orderBy('code')->get();
        $rows = [];
        $totals = array_fill(0, 5, 0.0);
        $add = function (string $key, string $label, float $open, float $debit, float $credit, int $indent = 1) use (&$rows, &$totals): void {
            $close = round($open + $debit - $credit, 2);
            $amounts = [round($open, 2), round($debit, 2), round($credit, 2), max($close, 0), max(-$close, 0)];
            $rows[] = ['key' => $key, 'label' => $label, 'type' => 'line', 'indent' => $indent, 'amounts' => $amounts, 'ratios' => []];

            foreach ([1, 2, 3, 4] as $index) {
                $totals[$index] += $amounts[$index];
            }
        };

        foreach (ChartOfAccounts::TYPES as $type => $typeLabel) {
            $group = $accounts->where('type', $type);
            $rows[] = ['key' => 'type:'.$type, 'label' => $typeLabel, 'type' => 'header', 'indent' => 0, 'amounts' => array_fill(0, 5, null), 'ratios' => []];

            foreach ($group as $account) {
                $open = in_array($type, $profitTypes, true) ? ($openingProfit[$account->id]->net ?? 0.0) : ($opening[$account->id]->net ?? 0.0);
                $debit = $movement[$account->id]->debit ?? 0.0;
                $credit = $movement[$account->id]->credit ?? 0.0;

                if ($open != 0 || $debit != 0 || $credit != 0) {
                    $add('account:'.$account->code, $account->code.' '.$account->name, (float) $open, (float) $debit, (float) $credit);
                }
            }

            if ($type === 'equity' && $priorProfit != 0) {
                $add('prior_profit', 'Laba rugi tahun-tahun sebelumnya (laba ditahan)', $priorProfit, 0, 0);
            }
        }

        $rows[] = ['key' => 'total', 'label' => 'TOTAL', 'type' => 'total', 'indent' => 0, 'amounts' => [null, round($totals[1], 2), round($totals[2], 2), round($totals[3], 2), round($totals[4], 2)], 'ratios' => []];

        return array_values(array_filter($rows, fn (array $row, int $index): bool => $row['type'] !== 'header'
            || (isset($rows[$index + 1]) && $rows[$index + 1]['type'] === 'line'), ARRAY_FILTER_USE_BOTH));
    }

    // ------------------------------------------------------------------ BANTUAN

    /**
     * Akun kas entitas: rekening konveksi & kas belum ditentukan, atau kas toko.
     *
     * @return list<int>
     */
    public function cashAccountIds(string $entity): array
    {
        return DB::table('accounts')
            ->where('ledger', GeneralLedger::ledgerOf($entity))
            ->where(fn ($query) => $query->whereNotNull('cash_account_id')->orWhereIn('role', ['cash_unassigned', 'store_cash']))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>|null  $accountIds
     */
    public function cashBalance(string $entity, Carbon $asOf, ?array $accountIds = null): float
    {
        $this->ledger->ensureFresh();
        $query = DB::table('journal_lines as l')
            ->whereIn('l.account_id', ($accountIds ?? $this->cashAccountIds($entity)) ?: [0])
            ->where('l.entry_date', '<=', $asOf->toDateString());
        $this->ledger->scope($query, $entity);

        return round((float) $query->sum(DB::raw('l.debit - l.credit')), 2);
    }

    /**
     * Potongan biaya oleh reseller (non-kas) pada periode.
     */
    private function offsetTotal(Carbon $from, Carbon $until): float
    {
        return round((float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.role', 'offset_clearing')->whereNull('l.store_id')
            ->whereBetween('l.entry_date', [$from->toDateString(), $until->toDateString()])
            ->sum('l.debit'), 2);
    }

    /**
     * @return list<int>
     */
    public function storeIds(string $entity): array
    {
        if (str_starts_with($entity, 'store:')) {
            return [(int) substr($entity, 6)];
        }

        return Store::withTrashed()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value * 100, 2, ',', '.'), '0'), ',').'%';
    }

    private static function key(object $account): string
    {
        return $account->role ?? 'account:'.$account->code;
    }

    /** @return array<string, mixed> */
    private static function header(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => null, 'type' => 'header', 'indent' => 0];
    }

    /** @return array<string, mixed> */
    private static function line(string $key, string $label, float $amount, int $indent = 1): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => round($amount, 2) + 0.0, 'type' => 'line', 'indent' => $indent];
    }

    /** @return array<string, mixed> */
    private static function subtotal(string $key, string $label, float $amount): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => round($amount, 2) + 0.0, 'type' => 'subtotal', 'indent' => 0];
    }

    /** @return array<string, mixed> */
    private static function total(string $key, string $label, float $amount, ?float $base = null): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => round($amount, 2) + 0.0, 'type' => 'total', 'indent' => 0,
            'ratio' => $base ? round($amount / $base, 4) : null];
    }

    /** @return array<string, mixed> */
    private static function note(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => null, 'type' => 'note', 'indent' => 0];
    }
}
