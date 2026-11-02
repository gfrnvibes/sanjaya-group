<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Actions\ResourceIo;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Services\FinancialStatements;
use App\Support\Spreadsheet;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Dasar halaman LAPORAN KEUANGAN: filter entitas (konveksi / toko / gabungan), periode (pilihan cepat atau tanggal),
 * pembanding (periode sebelumnya / tahun lalu), tampilan laporan bertingkat, Cetak / PDF & Excel.
 */
abstract class FinancialReportPage extends Page
{
    use HasPageShield;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected string $view = 'filament.pages.financial-report';

    public string $entity = FinancialStatements::KONVEKSI;

    public ?string $preset = 'this_month';

    public ?string $from = null;

    public ?string $until = null;

    public string $compare = 'none';

    public const COMPARES = [
        'none' => 'Tanpa pembanding',
        'previous' => 'Periode sebelumnya',
        'last_year' => 'Periode sama tahun lalu',
    ];

    /**
     * Baris laporan untuk entitas & periode.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function rows(string $entity, Carbon $from, Carbon $until): array;

    /** Judul laporan pada tampilan & cetakan. */
    abstract protected function reportTitle(): string;

    /** Laporan dengan kolom tetap (mis. neraca saldo) tidak memakai pembanding. */
    protected function supportsComparison(): bool
    {
        return true;
    }

    /** Laporan posisi (per tanggal) tidak memakai tanggal "dari". */
    protected function isPointInTime(): bool
    {
        return false;
    }

    public function mount(): void
    {
        [$this->from, $this->until] = DateRangeFilter::range($this->preset);
    }

    public function updatedPreset(?string $preset): void
    {
        if ($preset && $preset !== DateRangeFilter::CUSTOM) {
            [$this->from, $this->until] = DateRangeFilter::range($preset);
        }
    }

    public function updatedFrom(): void
    {
        $this->preset = DateRangeFilter::CUSTOM;
    }

    public function updatedUntil(): void
    {
        $this->preset = DateRangeFilter::CUSTOM;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->compact()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 5])->schema([
                        Select::make('entity')
                            ->label('Laporan untuk')
                            ->options(fn (): array => FinancialStatements::entities())
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->live()
                            ->columnSpan(['lg' => 2]),
                        Select::make('preset')
                            ->label('Periode')
                            ->options(DateRangeFilter::PRESETS)
                            ->selectablePlaceholder(false)
                            ->live(),
                        DatePicker::make('from')
                            ->label('Dari')
                            ->hidden(fn (): bool => $this->isPointInTime())
                            ->required()
                            ->live(),
                        DatePicker::make('until')
                            ->label($this->isPointInTime() ? 'Per tanggal' : 'Sampai')
                            ->required()
                            ->live(),
                        Select::make('compare')
                            ->label('Bandingkan dengan')
                            ->options(self::COMPARES)
                            ->selectablePlaceholder(false)
                            ->hidden(fn (): bool => ! $this->supportsComparison())
                            ->live()
                            ->columnSpan(['lg' => $this->isPointInTime() ? 2 : 1]),
                    ]),
                ]),
        ]);
    }

    /**
     * Laporan siap tampil: kolom (periode ini & pembanding) dan baris dengan nilai per kolom.
     *
     * @return array{title: string, entity: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>}
     */
    public function report(): array
    {
        [$from, $until] = $this->range();
        $periods = [[$from, $until]];

        if ($this->compare !== 'none') {
            $periods[] = $this->comparisonRange($from, $until);
        }

        $results = array_map(fn (array $period): array => $this->rows($this->entity, ...$period), $periods);

        return [
            'title' => $this->reportTitle(),
            'entity' => FinancialStatements::entities()[$this->entity] ?? $this->entity,
            'columns' => array_map(fn (array $period): string => $this->periodLabel(...$period), $periods),
            'rows' => self::merge($results),
            'notes' => $this->notes(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function notes(): array
    {
        return array_values(array_filter([
            $this->entity !== FinancialStatements::KONVEKSI ? 'Toko: estimasi iklan dijurnal per akhir bulan, jadi periode dibulatkan ke bulan penuh.' : null,
            'Disusun dari buku besar (jurnal otomatis tiap transaksi + jurnal manual). Beban yang belum diinput (mis. gaji) belum mengurangi laba.',
        ]));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(): array
    {
        $until = Carbon::parse($this->until ?: today());
        $from = $this->isPointInTime() ? $until->copy() : Carbon::parse($this->from ?: $until->copy()->startOfMonth());

        return $this->isPointInTime() ? [$from->min(today()), $until->min(today())] : FinancialStatements::period($this->entity, $from, $until);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function comparisonRange(Carbon $from, Carbon $until): array
    {
        if ($this->compare === 'last_year') {
            return [$from->copy()->subYearNoOverflow(), $until->copy()->subYearNoOverflow()];
        }

        // periode bulan penuh: mundur sejumlah bulan; selain itu mundur sejumlah hari
        if ($from->day === 1 && ($until->isLastOfMonth() || $until->isSameDay(today()))) {
            $months = $from->diffInMonths($until->copy()->startOfMonth()) + 1;

            return [$from->copy()->subMonthsNoOverflow((int) $months), $until->copy()->subMonthsNoOverflow((int) $months)->endOfMonth()->startOfDay()];
        }

        $days = (int) $from->diffInDays($until) + 1;

        return [$from->copy()->subDays($days), $until->copy()->subDays($days)];
    }

    protected function periodLabel(Carbon $from, Carbon $until): string
    {
        if ($this->isPointInTime()) {
            return 'Per '.$until->locale('id')->translatedFormat('d M Y');
        }

        return $from->locale('id')->translatedFormat('d M Y').' – '.$until->locale('id')->translatedFormat('d M Y');
    }

    /**
     * Gabungkan baris beberapa periode berdasarkan key (baris yang hanya ada di pembanding tetap ditampilkan).
     *
     * @param  list<list<array<string, mixed>>>  $results
     * @return list<array<string, mixed>>
     */
    public static function merge(array $results): array
    {
        $merged = [];

        foreach ($results as $index => $rows) {
            $previousKey = null;

            foreach ($rows as $row) {
                $key = $row['key'];

                if (! isset($merged[$key])) {
                    $entry = [...$row, 'amounts' => array_fill(0, count($results), null), 'ratios' => array_fill(0, count($results), null)];
                    $merged = $previousKey === null || ! isset($merged[$previousKey])
                        ? [$key => $entry, ...$merged]
                        : self::insertAfter($merged, $previousKey, $key, $entry);
                }

                $merged[$key]['amounts'][$index] = $row['amount'];
                $merged[$key]['ratios'][$index] = $row['ratio'] ?? null;
                $previousKey = $key;
            }
        }

        // baris "kosong" (0 di semua periode) di dalam rincian tidak perlu tampil, kecuali baris utama
        return array_values(array_filter($merged, fn (array $row): bool => $row['type'] !== 'line'
            || ! str_contains($row['key'], ':')
            || collect($row['amounts'])->contains(fn ($amount): bool => (float) $amount != 0)));
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private static function insertAfter(array $items, string $after, string $key, array $entry): array
    {
        $result = [];

        foreach ($items as $existingKey => $item) {
            $result[$existingKey] = $item;

            if ($existingKey === $after) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('print')
                    ->label('Cetak / PDF')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->action(fn () => ResourceIo::openPrint($this, view('print.financial-report', [
                        'reports' => [$this->report()],
                        'documentTitle' => $this->reportTitle(),
                        'printedBy' => auth()->user()?->name,
                    ])->render())),
                Action::make('excel')
                    ->label('Excel (.xlsx)')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->action(fn (): BinaryFileResponse => response()
                        ->download(self::excel([$this->report()]), Str::slug($this->reportTitle()).'-'.now()->format('Ymd-His').'.xlsx')
                        ->deleteFileAfterSend()),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * Excel: 1 sheet per laporan (label, nilai per periode).
     *
     * @param  list<array<string, mixed>>  $reports
     */
    public static function excel(array $reports): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($reports as $index => $report) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName(Spreadsheet::sheetName($report['sheet'] ?? $report['title']));
            $writer->getCurrentSheet()->setColumnWidth(55, 1);
            $writer->getCurrentSheet()->setColumnWidthForRange(22, 2, 1 + max(1, count($report['columns'])));
            $writer->addRow(Row::fromValues([$report['title'].' — '.$report['entity']]));
            $writer->addRow(Row::fromValues(['Keterangan', ...$report['columns']]));

            foreach ($report['rows'] as $row) {
                $writer->addRow(Row::fromValues([
                    str_repeat('   ', (int) $row['indent']).$row['label'],
                    ...array_map(fn ($amount) => $amount === null ? '' : (float) $amount, $row['amounts']),
                ]));
            }
        }

        $writer->close();

        return $path;
    }
}
