<?php

namespace App\Filament\Pages\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\LedgerClosing;
use App\Services\GeneralLedger;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * TUTUP BUKU bulanan: bulan yang ditutup tidak boleh diubah transaksinya dan jurnal otomatisnya dikunci.
 * Bila transaksi periode tertutup tetap berubah (mis. impor), selisihnya ditampilkan di sini.
 */
class LedgerClosingPage extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Tutup Buku';

    protected static ?string $title = 'Tutup Buku';

    protected static ?string $slug = 'akuntansi/tutup-buku';

    public function mount(): void
    {
        app(GeneralLedger::class)->ensureFresh();
    }

    public function getSubheading(): ?string
    {
        return 'Tutup buku setelah semua transaksi bulan itu lengkap & dicek. Bulan tertutup: transaksi tidak bisa ditambah/diubah dan jurnalnya dikunci. Buka kembali bila perlu koreksi.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Susun ulang jurnal')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    $result = app(GeneralLedger::class)->sync();
                    Notification::make()->title("Jurnal otomatis disusun ulang: {$result['entries']} jurnal, {$result['lines']} baris.")->success()->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perubahan di periode tertutup')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->iconColor('danger')
                ->description('Transaksi di bulan yang sudah ditutup berubah setelah ditutup (mis. lewat impor). Jurnalnya tidak ikut berubah; buka kembali bulan tersebut agar jurnal disusun ulang.')
                ->compact()
                ->visible(fn (): bool => $this->drift() !== [])
                ->schema([
                    TextEntry::make('drift')
                        ->hiddenLabel()
                        ->state(fn (): array => array_map(fn (array $row): string => $row['account'].': jurnal '.Number::currency($row['posted'], 'IDR', 'id', 0).' → transaksi '.Number::currency($row['expected'], 'IDR', 'id', 0), $this->drift()))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->color('danger'),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->months())
            ->columns([
                TextColumn::make('label')->label('Bulan')->weight('bold'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'closed' ? 'Ditutup' : 'Terbuka')
                    ->color(fn (string $state): string => $state === 'closed' ? 'success' : 'gray'),
                TextColumn::make('entries')->label('Jurnal')->numeric(),
                TextColumn::make('debit')->label('Total mutasi')->money('IDR', decimalPlaces: 0)->alignEnd(),
                TextColumn::make('profit')
                    ->label('Laba (konveksi)')
                    ->money('IDR', decimalPlaces: 0)
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                    ->alignEnd(),
                TextColumn::make('closed')->label('Ditutup oleh')->placeholder('-'),
            ])
            ->recordActions([
                Action::make('close')
                    ->label('Tutup buku')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record): string => "Tutup buku s.d. {$record['label']}? Bulan-bulan sebelumnya ikut ditutup. Transaksi di periode itu tidak bisa diubah sampai dibuka kembali.")
                    ->visible(fn (array $record): bool => $record['status'] === 'open' && $record['ended'])
                    ->action(fn (array $record) => $this->run(fn () => app(GeneralLedger::class)->close(Carbon::parse($record['month'])), 'Buku ditutup s.d. '.$record['label'].'.')),
                Action::make('reopen')
                    ->label('Buka kembali')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record): string => "Buka kembali {$record['label']} dan semua bulan sesudahnya? Jurnal otomatisnya akan disusun ulang dari transaksi terbaru.")
                    ->visible(fn (array $record): bool => $record['status'] === 'closed')
                    ->action(fn (array $record) => $this->run(fn () => app(GeneralLedger::class)->reopen(Carbon::parse($record['month'])), $record['label'].' dibuka kembali.')),
            ])
            ->paginated(false)
            ->emptyStateHeading('Belum ada jurnal');
    }

    private function run(callable $callback, string $message): void
    {
        try {
            $callback();
            Notification::make()->title($message)->success()->send();
        } catch (BusinessRuleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function months(): array
    {
        $stats = collect(DB::select(<<<'SQL'
            SELECT date_trunc('month', l.entry_date)::date AS month, COUNT(DISTINCT l.entry_id) AS entries, SUM(l.debit) AS debit,
                   -SUM(l.debit - l.credit) FILTER (WHERE l.store_id IS NULL AND a.type IN ('revenue', 'cogs', 'expense', 'other_income', 'other_expense')) AS profit
            FROM journal_lines l JOIN accounts a ON a.id = l.account_id GROUP BY 1 ORDER BY 1 DESC
            SQL))->keyBy('month');
        $closings = LedgerClosing::query()->with('closer:id,name')->get()->keyBy(fn (LedgerClosing $closing): string => $closing->month->toDateString());

        $rows = [];

        foreach ($stats as $month => $row) {
            $date = Carbon::parse($month);
            $closing = $closings[$month] ?? null;
            $rows[$month] = [
                'month' => $month,
                'label' => $date->locale('id')->translatedFormat('F Y'),
                'status' => $closing ? 'closed' : 'open',
                'ended' => $date->copy()->endOfMonth()->lt(today()),
                'entries' => (int) $row->entries,
                'debit' => (float) $row->debit,
                'profit' => (float) $row->profit,
                'closed' => $closing ? ($closing->closer?->name ?? '-').', '.$closing->closed_at->format('d/m/Y H:i') : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{account: string, expected: float, posted: float}>
     */
    private function drift(): array
    {
        return json_decode((string) DB::scalar('SELECT drift FROM ledger_state WHERE id = 1'), true) ?: [];
    }
}
