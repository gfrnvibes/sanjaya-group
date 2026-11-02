<?php

namespace App\Filament\Pages\Accounting;

use App\Filament\Pages\Reports\FinancialReportPage;
use App\Services\FinancialStatements;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * NERACA SALDO: saldo awal, mutasi debit/kredit dan saldo akhir semua akun; total debit = total kredit.
 */
class TrialBalance extends FinancialReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static ?string $title = 'Neraca Saldo';

    protected static ?string $slug = 'akuntansi/neraca-saldo';

    protected function reportTitle(): string
    {
        return 'Neraca Saldo';
    }

    protected function supportsComparison(): bool
    {
        return false;
    }

    protected function rows(string $entity, Carbon $from, Carbon $until): array
    {
        return [];
    }

    public function report(): array
    {
        [$from, $until] = $this->range();

        return [
            'title' => $this->reportTitle(),
            'entity' => FinancialStatements::entities()[$this->entity] ?? $this->entity,
            'columns' => ['Saldo awal (D - K)', 'Debit', 'Kredit', 'Saldo akhir debit', 'Saldo akhir kredit'],
            'rows' => app(FinancialStatements::class)->trialBalance($this->entity, $from, $until),
            'notes' => $this->notes(),
            'plain' => true,
        ];
    }

    /**
     * @return list<string>
     */
    protected function notes(): array
    {
        return [
            'Periode '.$this->periodText().'. Akun laba rugi dihitung sejak awal tahun buku; laba rugi tahun-tahun sebelumnya ditampilkan sebagai laba ditahan.',
            'Total debit = total kredit (mutasi maupun saldo akhir) menandakan semua jurnal seimbang.',
        ];
    }

    private function periodText(): string
    {
        [$from, $until] = $this->range();

        return $this->periodLabel($from, $until);
    }
}
