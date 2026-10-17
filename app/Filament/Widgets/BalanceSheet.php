<?php

namespace App\Filament\Widgets;

use App\Services\BalanceSheetService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * NERACA: posisi aset, kewajiban & ekuitas konveksi dan tiap toko per akhir bulan.
 */
class BalanceSheet extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.balance-sheet';

    protected int|string|array $columnSpan = 'full';

    public string $source = BalanceSheetService::KONVEKSI;

    public string $period = '';

    /** @var array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>|null */
    private ?array $sheets = null;

    public function mount(): void
    {
        $this->period = today()->format('Y-m');
    }

    public function updatedPeriod(): void
    {
        $this->sheets = null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Neraca Keuangan')
                    ->description(fn (): string => 'Posisi per '.$this->asOf()->locale('id')->translatedFormat('d F Y'))
                    ->icon(Heroicon::OutlinedScale)
                    ->collapsible()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                Select::make('source')
                                    ->label('Sumber')
                                    ->options(fn (): array => array_map(fn (array $sheet): string => $sheet['label'], $this->sheets()))
                                    ->selectablePlaceholder(false)
                                    ->searchable()
                                    ->live(),
                                Select::make('period')
                                    ->label('Per Akhir Bulan')
                                    ->options(fn (): array => $this->periodOptions())
                                    ->selectablePlaceholder(false)
                                    ->live(),
                            ]),
                        Grid::make(['default' => 1, 'lg' => 2])
                            ->schema([
                                Section::make('Aset')
                                    ->compact()
                                    ->schema($this->sectionEntries('assets', 'Total Aset')),
                                Grid::make(1)
                                    ->schema([
                                        Section::make('Kewajiban')
                                            ->compact()
                                            ->schema($this->sectionEntries('liabilities', 'Total Kewajiban')),
                                        Section::make('Ekuitas')
                                            ->compact()
                                            ->schema([
                                                ...$this->sectionEntries('equity', 'Total Ekuitas'),
                                                TextEntry::make('liabilities_and_equity')
                                                    ->label('Total Kewajiban + Ekuitas')
                                                    ->state(fn (): float => BalanceSheetService::total($this->sheet()['liabilities'])
                                                        + BalanceSheetService::total($this->sheet()['equity']))
                                                    ->money('IDR', decimalPlaces: 0)
                                                    ->weight(FontWeight::Bold)
                                                    ->inlineLabel(),
                                            ]),
                                    ]),
                            ]),
                        Section::make('Ringkasan Semua Sumber')
                            ->compact()
                            ->collapsible()
                            ->schema([
                                RepeatableEntry::make('summary')
                                    ->hiddenLabel()
                                    ->state(fn (): array => $this->summaryRows())
                                    ->table([
                                        TableColumn::make('Sumber'),
                                        TableColumn::make('Total Aset'),
                                        TableColumn::make('Kewajiban'),
                                        TableColumn::make('Ekuitas'),
                                        TableColumn::make('Laba Tahun Berjalan'),
                                    ])
                                    ->schema([
                                        TextEntry::make('label'),
                                        $this->moneyEntry('assets'),
                                        $this->moneyEntry('liabilities'),
                                        $this->moneyEntry('equity'),
                                        $this->moneyEntry('ytd_profit'),
                                    ]),
                            ]),
                        Section::make('Catatan Perhitungan')
                            ->compact()
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                TextEntry::make('notes')
                                    ->hiddenLabel()
                                    ->state([
                                        'Kas konveksi = pembayaran reseller (selain potongan biaya) - biaya - upah jahit - pembelian bahan - pembelian barang jadi/borongan. Saldo awal kas belum tercatat, jadi bisa minus.',
                                        'Persediaan barang jadi dinilai dengan HPP yang berlaku; bahan baku dengan rata-rata harga beli; barang dalam proses dengan biaya bahan SPK yang belum selesai.',
                                        'Toko: pembayaran reseller dialokasikan ke tiap toko sebanding tagihan bersihnya. Kas toko = pendapatan - fee admin - iklan - pembayaran ke konveksi.',
                                        'Laba toko memakai laporan laba rugi bulanan, sehingga neraca toko dihitung per akhir bulan.',
                                        'Pos "Modal, Saldo Awal & Laba Ditahan" adalah penyeimbang: berisi modal disetor, saldo awal, dan selisih transaksi yang belum tercatat.',
                                    ])
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->color('gray'),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return list<RepeatableEntry|TextEntry>
     */
    private function sectionEntries(string $section, string $totalLabel): array
    {
        return [
            RepeatableEntry::make($section)
                ->hiddenLabel()
                ->state(fn (): array => $this->sheet()[$section])
                ->table([
                    TableColumn::make('Pos'),
                    TableColumn::make('Jumlah'),
                ])
                ->schema([
                    TextEntry::make('label'),
                    $this->moneyEntry('amount'),
                ]),
            TextEntry::make("{$section}_total")
                ->label($totalLabel)
                ->state(fn (): float => BalanceSheetService::total($this->sheet()[$section]))
                ->money('IDR', decimalPlaces: 0)
                ->weight(FontWeight::Bold)
                ->inlineLabel(),
        ];
    }

    private function moneyEntry(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->money('IDR', decimalPlaces: 0)
            ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null);
    }

    /**
     * @return list<array{label: string, assets: float, liabilities: float, equity: float, ytd_profit: float}>
     */
    private function summaryRows(): array
    {
        return array_values(array_map(fn (array $sheet): array => [
            'label' => $sheet['label'],
            'assets' => BalanceSheetService::total($sheet['assets']),
            'liabilities' => BalanceSheetService::total($sheet['liabilities']),
            'equity' => BalanceSheetService::total($sheet['equity']),
            'ytd_profit' => $sheet['ytd_profit'],
        ], $this->sheets()));
    }

    /**
     * @return array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}
     */
    private function sheet(): array
    {
        $sheets = $this->sheets();

        return $sheets[$this->source] ?? $sheets[BalanceSheetService::KONVEKSI];
    }

    /**
     * @return array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>
     */
    private function sheets(): array
    {
        return $this->sheets ??= app(BalanceSheetService::class)->build($this->asOf());
    }

    private function asOf(): Carbon
    {
        $month = rescue(fn (): Carbon => Carbon::createFromFormat('!Y-m', $this->period), today()->startOfMonth(), report: false);

        return $month->endOfMonth()->min(today());
    }

    /**
     * @return array<string, string>
     */
    private function periodOptions(): array
    {
        $options = [];
        $month = today()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $options[$month->format('Y-m')] = $month->locale('id')->translatedFormat('F Y');
            $month = $month->copy()->subMonth();
        }

        return $options;
    }
}
