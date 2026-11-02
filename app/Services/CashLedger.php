<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KAS KONVEKSI dari buku besar (akun kas per rekening + kas belum ditentukan rekening).
 * Transfer antar-rekening tidak dihitung sebagai kas masuk/keluar.
 */
class CashLedger
{
    public function __construct(private GeneralLedger $ledger, private FinancialStatements $statements) {}

    /**
     * Saldo kas s.d. akhir tanggal $asOf.
     */
    public function balance(Carbon $asOf): float
    {
        return $this->statements->cashBalance(GeneralLedger::KONVEKSI, $asOf);
    }

    /**
     * Kas masuk & keluar per bulan.
     *
     * @param  list<Carbon>  $months
     * @return array{in: list<float>, out: list<float>}
     */
    public function monthly(array $months): array
    {
        $this->ledger->ensureFresh();
        $cash = $this->statements->cashAccountIds(GeneralLedger::KONVEKSI) ?: [0];
        $placeholders = implode(', ', array_fill(0, count($cash), '?'));

        // Per jurnal: kas bersih (masuk +, keluar -); jurnal transfer antar-kas bernilai 0.
        $rows = collect(DB::select(<<<SQL
            SELECT to_char(entry_date, 'YYYY-MM') AS month,
                   SUM(net) FILTER (WHERE net > 0) AS cash_in, -SUM(net) FILTER (WHERE net < 0) AS cash_out
            FROM (SELECT l.entry_id, MIN(l.entry_date) AS entry_date, SUM(l.debit - l.credit) AS net
                  FROM journal_lines l WHERE l.store_id IS NULL AND l.account_id IN ({$placeholders}) AND l.entry_date BETWEEN ? AND ?
                  GROUP BY l.entry_id) x
            GROUP BY 1
            SQL, [...$cash, $months[0]->toDateString(), end($months)->copy()->endOfMonth()->toDateString()]))->keyBy('month');

        $pick = fn (string $column): array => array_map(fn (Carbon $month): float => round((float) ($rows[$month->format('Y-m')]->{$column} ?? 0), 2), $months);

        return ['in' => $pick('cash_in'), 'out' => $pick('cash_out')];
    }
}
